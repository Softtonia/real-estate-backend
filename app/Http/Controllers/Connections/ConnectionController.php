<?php

namespace App\Http\Controllers\Connections;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Http\Requests\StoreConnectionRequest;
use App\Http\Requests\ProcessConnectionRequest;
use App\Models\Connection;
use App\Models\User;
use App\Services\AssociationService;

use Illuminate\Support\Facades\DB;

class ConnectionController extends Controller
{
     protected AssociationService $assoc;

    public function __construct(AssociationService $assoc)
    {
        $this->assoc = $assoc;
    }

    public function store(StoreConnectionRequest $request)
    {
        $receiver = $request->getReceiver();
        $requester = $request->user();

        if (!$receiver || $receiver->id === $requester->id) {
            return response()->json(['message' => 'Invalid target user.'], 422);
        }

        if (!$this->isSearchAllowed($requester->role->name, $receiver->role->name)) {
            return response()->json(['message' => 'Not allowed to connect to this role.'], 403);
        }

        try {
            $connection = DB::transaction(function () use ($requester, $receiver, $request) {
                $existing = Connection::where('requester_id', $requester->id)
                    ->where('receiver_id', $receiver->id)
                    ->whereIn('state', ['pending', 'accepted'])
                    ->first();

                if ($existing) {
                    throw new \Exception('Connection already exists or pending.');
                }

                return Connection::create([
                    'requester_id' => $requester->id,
                    'receiver_id' => $receiver->id,
                    'note' => $request->input('note'),
                    'meta' => $request->input('meta'),
                    'created_by' => $requester->id,
                ]);
            });

            // New pending request; invalidate caches for both users (so lists reflect pending/accepted correctly)
            $this->assoc->flushForUsers($requester->id, $receiver->id);

            return response()->json(['message' => 'Request sent', 'data' => $connection], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function accept(ProcessConnectionRequest $request, Connection $connection)
    {
        $user = $request->user();

        if ($connection->receiver_id !== $user->id || $connection->state !== 'pending') {
            return response()->json(['message' => 'Cannot accept this request.'], 403);
        }

        DB::transaction(function () use ($connection, $user) {
            $connection->update([
                'state' => 'accepted',
                'accepted_at' => now(),
                'updated_by' => $user->id,
            ]);
        });

        // Important: flush both users' caches so new association shows up immediately
        $this->assoc->flushForUsers($connection->requester_id, $connection->receiver_id);

        return response()->json(['message' => 'Accepted', 'data' => $connection->fresh()]);
    }

    public function reject(ProcessConnectionRequest $request, Connection $connection)
    {
        $user = $request->user();

        if ($connection->receiver_id !== $user->id || $connection->state !== 'pending') {
            return response()->json(['message' => 'Cannot reject this request.'], 403);
        }

        DB::transaction(function () use ($connection, $user) {
            $connection->update([
                'state' => 'rejected',
                'rejected_at' => now(),
                'updated_by' => $user->id,
            ]);
        });

        $this->assoc->flushForUsers($connection->requester_id, $connection->receiver_id);

        return response()->json(['message' => 'Rejected', 'data' => $connection->fresh()]);
    }

    public function destroy(Request $request, Connection $connection)
    {
        $user = $request->user();

        if ($connection->state === 'accepted') {
            if (!in_array($user->id, [$connection->requester_id, $connection->receiver_id])) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $connection->update([
                'state' => 'left',
                'left_at' => now(),
                'updated_by' => $user->id,
            ]);

            $this->assoc->flushForUsers($connection->requester_id, $connection->receiver_id);

            return response()->json(['message' => 'Connection ended']);
        }

        if ($connection->state === 'pending' && $connection->requester_id === $user->id) {
            $connection->delete();
            $this->assoc->flushForUsers($connection->requester_id, $connection->receiver_id);
            return response()->json(['message' => 'Request cancelled']);
        }

        return response()->json(['message' => 'Cannot remove this connection'], 403);
    }

    // keep the same mapping you rely upon for allowed searching
    // protected function isSearchAllowed($fromRole, $toRole)
    // {
    //     $map = [
    //         'company' => ['consultancy', 'developer', 'agent'],
    //         'consultancy' => ['company', 'agent'],
    //         'agent' => ['consultancy'],
    //         'developer' => ['company'],
    //     ];

    //     return in_array($toRole, $map[$fromRole] ?? []);
    // }

    protected function isSearchAllowed(string $fromRole, string $toRole): bool
    {
        $map = [
            'company'     => ['consultancy', 'developer', 'agent'],
            'consultancy' => ['company', 'agent'],
            'agent'       => ['consultancy'],
            'developer'   => ['company'],
        ];

        return in_array($toRole, $map[$fromRole] ?? []);
    }
}
