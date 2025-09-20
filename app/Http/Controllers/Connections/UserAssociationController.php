<?php

namespace App\Http\Controllers\Connections;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AssociationService;
use App\Http\Resources\UserResource;
use App\Models\User;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class UserAssociationController extends Controller
{
    

    protected AssociationService $assoc;

    public function __construct(AssociationService $assoc)
    {
        $this->assoc = $assoc;
    }

    /**
     * GET /api/my/associations?role=consultancy&page=1&per_page=20
     */
    public function associations(Request $request)
    {
        $user = $request->user();
        $role = $request->query('role');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        // 1) Get associated IDs (cached)
        $allIds = $this->assoc->getAssociatedIds($user->id, $role);

        $total = count($allIds);
        $offset = ($page - 1) * $perPage;
        if ($offset >= $total) {
            // empty page
            $paginator = new LengthAwarePaginator([], $total, $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
                'query' => $request->query(),
            ]);

            return response()->json($paginator);
        }

        $pageIds = array_slice($allIds, $offset, $perPage);

        // 2) Fetch only these users & preserve ordering
        $users = User::whereIn('id', $pageIds)
            ->get()
            ->sortBy(fn($u) => array_search($u->id, $pageIds))
            ->values();

        // 3) Wrap in resource + paginator
        $items = UserResource::collection($users);

        $paginator = new LengthAwarePaginator(
            $items->values()->all(),
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => $request->query(),
            ]
        );

        return response()->json($paginator);
    }

    // convenience endpoints
    public function consultancies(Request $request) { 
        $request->merge(['role' => 'consultancy']);
        return $this->associations($request); 
    }
    public function companies(Request $request)     { 
        $request->merge(['role' => 'company']);     
         return $this->associations($request); 
        }
    public function agents(Request $request)       { 
        $request->merge(['role' => 'agent']);      
          return $this->associations($request);
         }
    public function developers(Request $request)   { 
        $request->merge(['role' => 'developer']);
            return $this->associations($request); 
        }


}
