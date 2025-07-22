<?php

namespace App\Http\Controllers\OvervewAnalytics;

use App\Http\Controllers\Controller;
use App\Models\PropertyList;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerDashboardAnalyticsController extends Controller
{
    //


     public function ownerDashboardAnalytics(Request $request)
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

                'ticket_status' => $ticket_status,
                'ticket_priority' => $ticket_priority,
                'ticket_type' => $ticket_types,

            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



     // this is for overview
    // public function ownerDashboardAnalytics(Request $request)
    // {
    //     try {

    //         $agentIds = User::where('role_id', 3)->pluck('id')->toArray();
    //         $companyIds = User::where('role_id', 4)->pluck('id')->toArray();
    //         $consultancyIds = User::where('role_id', 5)->pluck('id')->toArray();
    //         $developerIds = User::where('role_id', 6)->pluck('id')->toArray();


    //         $totalAgentProjectCount = PropertyList::whereIn('user_id', $agentIds)->count();
    //         $totalCompanyProjectCount = PropertyList::whereIn('user_id', $companyIds)->count();
    //         $totalConsultancyProjectCount = PropertyList::whereIn('user_id', $consultancyIds)->count();
    //         $totalDeveloperProjectCount = PropertyList::whereIn('user_id', $developerIds)->count();


    //         // Construct the return data
    //         $return = [
    //             'total_agent_property_count' => $totalAgentProjectCount,
    //             'total_company_property_count' => $totalCompanyProjectCount,
    //             'total_consultancy_property_count' => $totalConsultancyProjectCount,
    //             'total_developer_property_count' => $totalDeveloperProjectCount,
    //         ];

    //         return response()->json($return);
    //     } catch (\Throwable $th) {
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

}
