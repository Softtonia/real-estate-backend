<?php

namespace App\Http\Controllers\OvervewAnalytics;

use App\Http\Controllers\Controller;
use App\Models\Developerlist;
use App\Models\ProjectList;
use App\Models\PropertyList;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardAnalyticsController extends Controller
{
    // this is for overview
    public function adminDashboardAnalytics(Request $request)
    {
        try {

            ## Properties Count
            $totalPropertyCount = PropertyList::count();
            $approvedPropertyCount = PropertyList::where('live_status', 'Approve')->count();
            $rejectPropertyCount = PropertyList::where('live_status', 'Reject')->count();
            $underReviewPropertyCount = PropertyList::where('live_status', 'Under Review')->count();
            $disapprovePropertyCount = PropertyList::where('live_status', 'Disapprove')->count();
            $modifyReviewPropertyCount = PropertyList::where('live_status', 'Modify Review')->count();

            ## Projects Count
            $totalProjectCount = ProjectList::count();
            $approvedProjectCount = ProjectList::where('live_status', 'Approve')->count();
            $rejectProjectCount = ProjectList::where('live_status', 'Reject')->count();
            $underReviewProjectCount = ProjectList::where('live_status', 'Under Review')->count();
            $disapproveProjectCount = ProjectList::where('live_status', 'Disapprove')->count();
            $modifyReviewProjectCount = ProjectList::where('live_status', 'Modify Review')->count();

            ## Developers Count
             $totalDeveloperCount = Developerlist::count();
            $approvedDeveloperCount = Developerlist::where('live_status', 'Approve')->count();
            $rejectDeveloperCount = Developerlist::where('live_status', 'Reject')->count();
            $underReviewDeveloperCount = Developerlist::where('live_status', 'Under Review')->count();
            $disapproveDeveloperCount = Developerlist::where('live_status', 'Disapprove')->count();
            $modifyReviewDeveloperCount = Developerlist::where('live_status', 'Modify Review')->count();


            ## Users Count
            $allUserCount = User::where('role_id','!=', 1)->count();

            // Count by role
            $ownerCount = User::where('role_id', 2)->count();
            $agentCount = User::where('role_id', 3)->count();
            $companyCount = User::where('role_id', 4)->count();
            $consultancyCount = User::where('role_id', 5)->count();
            $developerCount = User::where('role_id', 6)->count();

            // Count by approval status
            $activeUserCount = User::where('isapproved', 1)->where('role_id','!=', 1)->count();
            $deactiveUserCount = User::where('isapproved', 2)->where('role_id','!=', 1)->count();
            $underReviewUserCount = User::where('isapproved', 3)->where('role_id','!=', 1)->count();
            $rejectedUserCount = User::where('isapproved', 4)->where('role_id','!=', 1)->count();


            $ticket_status = TicketStatus::get();

            foreach ($ticket_status as $row) {
                $ticketCount = Ticket::where('status_id', $row->id)->count();
                $row->ticket_count = $ticketCount;
            }

            $ticket_priority = TicketPriority::get();

            foreach ($ticket_priority as $row) {
                $ticketCount = Ticket::where('priority_id', $row->id)->count();
                $row->ticket_count = $ticketCount;
            }

            $ticket_types = TicketType::get();

            foreach ($ticket_types as $row) {
                $ticketCount = Ticket::where('ticket_type_id', $row->id)->count();
                $row->ticket_count = $ticketCount;
            }



             // Keywords count by keyword_type
            $keywordCounts = DB::table('import_keywords')
                ->select('keyword_type', DB::raw('COUNT(*) as total_keywords'))
                ->groupBy('keyword_type')
                ->get();

            // Format as associative array if needed
            $formattedKeywordCounts = $keywordCounts->mapWithKeys(function ($item) {
                return [$item->keyword_type => $item->total_keywords];
            });

            // Construct the return data
            $return = [
                'total_property_count' => $totalPropertyCount,
                'total_approve_property_count' => $approvedPropertyCount,
                'total_reject_property_count' => $rejectPropertyCount,
                'total_under_review_property_count' => $underReviewPropertyCount,
                'total_disapprove_property_count' => $disapprovePropertyCount,
                'total_modify_review_property_count' => $modifyReviewPropertyCount,

                'total_project_count' => $totalProjectCount,
                'total_approve_project_count' => $approvedProjectCount,
                'total_reject_project_count' => $rejectProjectCount,
                'total_under_review_project_count' => $underReviewProjectCount,
                'total_disapprove_project_count' => $disapproveProjectCount,
                'total_modify_review_project_count' => $modifyReviewProjectCount,

                'total_developer_count' => $totalDeveloperCount,
                'total_approve_developer_count' => $approvedDeveloperCount,
                'total_reject_developer_count' => $rejectDeveloperCount,
                'total_under_review_developer_count' => $underReviewDeveloperCount,
                'total_disapprove_developer_count' => $disapproveDeveloperCount,
                'total_modify_review_developer_count' => $modifyReviewDeveloperCount,

                'all_user_count' => $allUserCount,
                'owner_count' => $ownerCount,
                'agent_count' => $agentCount,
                'company_count' => $companyCount,
                'consultancy_count' => $consultancyCount,
                'developer_count' => $developerCount,
                'user_status_count' => [
                    'active_user_count' => $activeUserCount,
                    'deactive_user_count' => $deactiveUserCount,
                    'under_review_user_count' => $underReviewUserCount,
                    'rejected_user_count' => $rejectedUserCount,
                ],


                'ticket_status' => $ticket_status,
                'ticket_priority' => $ticket_priority,
                'ticket_type' => $ticket_types,

                'keyword_counts' => $formattedKeywordCounts,
            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
}
