<?php

namespace App\Http\Controllers\OvervewAnalytics;

use App\Http\Controllers\Controller;
use App\Models\Developerlist;
use App\Models\ProjectList;
use App\Models\PropertyList;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Http\Request;

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



            $ownerCount = User::where('role_id', 2)->count();
            $agentCount = User::where('role_id', 3)->count();
            $companyCount = User::where('role_id', 4)->count();
            $consultancyCount = User::where('role_id', 5)->count();
            $developerCount = User::where('role_id', 6)->count();

            $ticket_status = TicketStatus::get();

            foreach ($ticket_status as $row) {
                $ticketCount = Ticket::where('status_id', $row->id)->count();
                $row->ticket_count = $ticketCount;
            }

            // Construct the return data
            $return = [
                'total_property_count' => $totalPropertyCount,
                'approved_property_count' => $approvedPropertyCount,
                'reject_property_count' => $rejectPropertyCount,
                'under_review_property_count' => $underReviewPropertyCount,
                'disapprove_property_count' => $disapprovePropertyCount,
                'modify_review_property_count' => $modifyReviewPropertyCount,

                'total_project_count' => $totalProjectCount,
                'approved_project_count' => $approvedProjectCount,
                'reject_project_count' => $rejectProjectCount,
                'under_review_project_count' => $underReviewProjectCount,
                'disapprove_project_count' => $disapproveProjectCount,
                'modify_review_project_count' => $modifyReviewProjectCount,

                'total_developer_count' => $totalDeveloperCount,
                'approved_developer_count' => $approvedDeveloperCount,
                'reject_developer_count' => $rejectDeveloperCount,
                'under_review_developer_count' => $underReviewDeveloperCount,
                'disapprove_developer_count' => $disapproveDeveloperCount,
                'modify_review_developer_count' => $modifyReviewDeveloperCount,

                'owner_count' => $ownerCount,
                'agent_count' => $agentCount,
                'company_count' => $companyCount,
                'consultancy_count' => $consultancyCount,
                'developer_count' => $developerCount,

                'ticket_status' => $ticket_status,
            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
}
