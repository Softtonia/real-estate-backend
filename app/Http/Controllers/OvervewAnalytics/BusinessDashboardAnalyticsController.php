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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BusinessDashboardAnalyticsController extends Controller
{
    public function businessDashboardAnalytics(Request $request)
    {
        try {

            $authUser = Auth::user();

            ## Properties Count
            $totalPropertyCount = PropertyList::where('created_by',$authUser->id)->count();
            $approvedPropertyCount = PropertyList::where('live_status', 'Approve')->where('created_by',$authUser->id)->count();
            $rejectPropertyCount = PropertyList::where('live_status', 'Reject')->where('created_by',$authUser->id)->count();
            $underReviewPropertyCount = PropertyList::where('live_status', 'Under Review')->where('created_by',$authUser->id)->count();
            $disapprovePropertyCount = PropertyList::where('live_status', 'Disapprove')->where('created_by',$authUser->id)->count();
            $modifyReviewPropertyCount = PropertyList::where('live_status', 'Modify Review')->where('created_by',$authUser->id)->count();

            ## Projects Count
            $totalProjectCount = ProjectList::where('created_by',$authUser->id)->count();
            $approvedProjectCount = ProjectList::where('live_status', 'Approve')->where('created_by',$authUser->id)->count();
            $rejectProjectCount = ProjectList::where('live_status', 'Reject')->where('created_by',$authUser->id)->count();
            $underReviewProjectCount = ProjectList::where('live_status', 'Under Review')->where('created_by',$authUser->id)->count();
            $disapproveProjectCount = ProjectList::where('live_status', 'Disapprove')->where('created_by',$authUser->id)->count();
            $modifyReviewProjectCount = ProjectList::where('live_status', 'Modify Review')->where('created_by',$authUser->id)->count();

            ## Developers Count
             $totalDeveloperCount = Developerlist::where('created_by',$authUser->id)->count();
            $approvedDeveloperCount = Developerlist::where('live_status', 'Approve')->where('created_by',$authUser->id)->count();
            $rejectDeveloperCount = Developerlist::where('live_status', 'Reject')->where('created_by',$authUser->id)->count();
            $underReviewDeveloperCount = Developerlist::where('live_status', 'Under Review')->where('created_by',$authUser->id)->count();
            $disapproveDeveloperCount = Developerlist::where('live_status', 'Disapprove')->where('created_by',$authUser->id)->count();
            $modifyReviewDeveloperCount = Developerlist::where('live_status', 'Modify Review')->where('created_by',$authUser->id)->count();






            $ticket_status = TicketStatus::get();

            foreach ($ticket_status as $row) {
                $ticketCount = Ticket::where('status_id', $row->id)->where('user_id',$authUser->id)->count();
                $row->ticket_count = $ticketCount;
            }

            $ticket_priority = TicketPriority::get();

            foreach ($ticket_priority as $row) {
                $ticketCount = Ticket::where('priority_id', $row->id)->where('user_id',$authUser->id)->count();
                $row->ticket_count = $ticketCount;
            }

            $ticket_types = TicketType::get();

            foreach ($ticket_types as $row) {
                $ticketCount = Ticket::where('ticket_type_id', $row->id)->where('user_id',$authUser->id)->count();
                $row->ticket_count = $ticketCount;
            }





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




                'ticket_status' => $ticket_status,
                'ticket_priority' => $ticket_priority,
                'ticket_type' => $ticket_types,

            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
}
