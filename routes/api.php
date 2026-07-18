<?php

use App\Http\Controllers\Admin\ApiClient\ApiClientController;
use App\Http\Controllers\Admin\DashboardAnalyticsController;
use App\Http\Controllers\AgentProject\AgentProjectController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CompanyConsultancy\CompanyConsultancyController;
use App\Http\Controllers\CompanyProject\CompanyProjectController;
use App\Http\Controllers\ConsultancyProject\ConsultancyProjectController;
use App\Http\Controllers\ContactUsLead\ContactUsLeadController;
use App\Http\Controllers\CustomMultipleFieldController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\HelpActivityController;
use App\Http\Controllers\IpLog\IpLogController;

use App\Http\Controllers\Lead\LeadController;
use App\Http\Controllers\Lead\LeadTypeController;
use App\Http\Controllers\OvervewAnalytics\AdminDashboardAnalyticsController;
use App\Http\Controllers\OvervewAnalytics\BusinessDashboardAnalyticsController;
use App\Http\Controllers\OvervewAnalytics\OwnerDashboardAnalyticsController;
use App\Http\Controllers\Page\PageController;
use App\Http\Controllers\SearchEngine\SearchEngineController;
use App\Http\Controllers\SiteSetting\SiteSettingController;
use App\Http\Controllers\Subscribe\SubscribeController;
use App\Http\Controllers\TopFeature\TopFeatureController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\PropertyListing\PropertylistingController;
use App\Http\Controllers\Location\Locationcontroller;
use App\Http\Controllers\Status\statuscontroller;
use App\Http\Controllers\Admin\Admincontroller;
use App\Http\Controllers\Admin\ApiClient\ApiAuthFailureController;
use App\Http\Controllers\Admin\ApiClient\ApplicationPasswordController;
use App\Http\Controllers\Admin\ApiClient\BlockedApiIpController;
use App\Http\Controllers\Rolecontroller;
use App\Http\Controllers\Permissioncontroller;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\Ticket\TicketController;
use App\Http\Controllers\Ticket\TicketDepartmentController;
use App\Http\Controllers\Ticket\TicketPriorityController;
use App\Http\Controllers\Ticket\TicketStatusController;
use App\Http\Controllers\Ticket\TicketTypeController;
use App\Http\Controllers\Agent\AgentController;
use App\Http\Controllers\Media\MediaController;
use App\Http\Controllers\Builder\Buildercontroller;
use App\Http\Controllers\Profile\profilecontroller;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PropertylistingController as frontPropertylistingController;
use App\Http\Controllers\ProjectlistingController as frontProjectlistingController;
use App\Http\Controllers\Setting\SettingController;
use App\Http\Controllers\ProjectListing\ProjectlistingController;
use App\Http\Controllers\ClientReviewController;
use App\Http\Controllers\FaqCategoryController;
use App\Http\Controllers\FaqController;

use App\Http\Controllers\Page\servicescontroller;
use App\Http\Controllers\Page\AboutusController;

use App\Http\Controllers\Page\PropertyValuationController;
use App\Http\Controllers\Help\HelpCategoryController;
use App\Http\Controllers\Help\HelpSubcategoryController;
use App\Http\Controllers\Help\HelpChildcategoryController;
use App\Http\Controllers\Help\HelpArticleController;
use App\Http\Controllers\DeveloperListing\DeveloperlistingController;
use App\Http\Controllers\Admin\MailConfigController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Api\CustomFieldGroupController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\EmailOtpController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\CustomField\CustomFieldExportImportController;

use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\UserAssociationController;

use App\Http\Controllers\Auth\Kyc\KycController;

use App\Http\Controllers\BusinessEnquiry\BusinessEnquiryController;
use App\Http\Controllers\Template\CustomWidgetController;
use App\Http\Controllers\Template\TemplateController;
use App\Http\Controllers\Template\TemplateBuilderController;
use App\Http\Controllers\Template\TemplateDisplayConditionController;
use App\Http\Controllers\Template\TemplateComponentController;
use App\Http\Controllers\Template\TemplateApiController;

use App\Http\Controllers\Api\KeywordController;
use App\Http\Controllers\Api\PostTypeController;
use App\Http\Controllers\Api\PostTypeExportImportController;
use App\Http\Controllers\Api\TaxonomyController;
use App\Http\Controllers\Api\TaxonomyExportImportController;
use App\Http\Controllers\Api\TaxonomyTermController;
use App\Http\Controllers\Api\DynamicCustomFieldController;
use App\Http\Controllers\Api\PostTaxonomyTermController;
use App\Http\Controllers\Api\CustomFieldGroupExportImportController;
use App\Http\Controllers\Api\DynamicPostController;
use App\Http\Controllers\Api\DynamicPostCsvController;
use App\Http\Controllers\Api\DynamicPostFormStepController;
use App\Http\Controllers\Api\KeywordExportController;
use App\Http\Controllers\Api\KeywordImportController;
use App\Http\Controllers\Api\ListingUserAssignmentController;
use App\Http\Controllers\Api\PageBuilder\DynamicFieldApiController;
use App\Http\Controllers\Api\PageBuilder\WidgetApiController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\UserPropertyListingController;
use App\Http\Controllers\Frontend\FrontendListingController;
use App\Http\Controllers\Frontend\FrontendListingTaxonomyController;
use App\Http\Controllers\Template\PageBuilderContextController;
use App\Http\Controllers\Template\TemplateConflictController;
use App\Http\Controllers\Template\TemplateDuplicateController;
use App\Http\Controllers\Template\TemplateDynamicFieldController;
use App\Http\Controllers\Template\TemplateExportImportController;
use App\Http\Controllers\Template\TemplateListController;
use App\Http\Controllers\Template\TemplatePreviewController;
use App\Http\Controllers\Template\TemplatePublishValidationController;
use App\Http\Controllers\Template\TemplateResolveController;
use App\Http\Controllers\Template\TemplateRevisionController;
use App\Http\Controllers\Template\TemplateTrashController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
// User route will start from here

Route::get('/check-ip', function (Request $request) {
    return response()->json([
        'client_ip' => $request->ip(),
        'forwarded' => $request->header('X-Forwarded-For'),
        'real_ip'   => $request->header('X-Real-IP'),
        'remote'    => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

Route::middleware(['validate.api.client'])
    ->get('/app-access-check', function (Request $request) {
        $client = $request->attributes->get('api_client');
        $applicationPassword = $request->attributes->get('application_password');

        return response()->json([
            -'success' => true,
            'message' => 'Application access verified successfully.',
            'data' => [
                'api_client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                    'type' => $client->type,
                    'status' => $client->isActive(),
                    'allowed_origins' => $client->allowed_origins ?? [],
                    'permissions' => $client->permissions ?? [],
                    'requires_signature' => method_exists($client, 'isSignatureRequired')
                        ? $client->isSignatureRequired()
                        : (bool) $client->requires_signature,
                ],
                'application_password' => [
                    'id' => $applicationPassword->id,
                    'name' => $applicationPassword->name,
                    'permissions' => $applicationPassword->permissions ?? [],
                ],
                'request' => [
                    'app_type' => $request->header('X-App-Type'),
                    'origin' => $request->header('Origin') ?: $request->header('X-App-Origin'),
                    'ip' => $request->ip(),
                ],
            ],
        ]);
    });

Route::middleware(['validate.api.client'])->group(function () {

    Route::post('/register', [AuthController::class, 'register'])->middleware(['throttle:60,1']);

    Route::post('/store-otp-verification-data', [UserController::class, 'storeOtpVerificationData'])->middleware(['throttle:60,1']);

    Route::post('login', [AuthController::class, 'login'])->middleware(['throttle:60,1']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('api.token');

    Route::post('/check-unique', [UserController::class, 'checkUnique'])->middleware(['throttle:60,1']);
    Route::post('/admin/profile/change-password', [UserController::class, 'changePassword'])->middleware('api.token', 'throttle:60,1');

    Route::post('forget-password', [ForgotPasswordController::class, 'forgetPassword'])->middleware(['throttle:60,1']);
    Route::post('/reset-password-from', [ForgotPasswordController::class, 'resetPassword'])->middleware(['throttle:60,1']);
    Route::get('/validate-reset-token', [ForgotPasswordController::class, 'validateResetToken'])->middleware(['throttle:60,1']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('/user/search', [UserController::class, 'SearchUser']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('all-user-listing', [UserController::class, 'alluserlist']);


    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->get('get-details-byuserid', [UserController::class, 'getdetailsbyuserid']); // Done By softtonia
    Route::middleware(['throttle:60,1', 'allrole.token'])->post('update-current-user-by-token', [UserController::class, 'updateCurrentUser']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('user-kyc-update', [KycController::class, 'updateKycStatus']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->post('user-complete-kyc', [KycController::class, 'completeKyc']);


    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-user-byuserid', [UserController::class, 'updateuserbyid']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-user-status', [UserController::class, 'updateuserstatus']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('create-user', [UserController::class, 'createUser']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->post('update-front-user-by-id', [UserController::class, 'updateUser']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('delete-user', [UserController::class, 'deleteUser']);
    Route::post('user-bulk-delete', [UserController::class, 'bulkDelete'])->middleware(['throttle:60,1']);
    Route::get('/users/filter-by-role', [UserController::class, 'filterByRole'])->middleware(['throttle:60,1']);
    Route::get('/users/filter-by-status', [UserController::class, 'filterByStatus'])->middleware(['throttle:60,1']);
    Route::get('/get-user-status', [UserController::class, 'getUserStatusList'])->middleware(['throttle:60,1']);

    Route::get('/get-all-users-by-role', [UserController::class, 'getDataUserDetailsByRole'])->middleware(['throttle:60,1']);
    Route::get('/get-userdata-by-id', [UserController::class, 'getDataUserDetailsById'])->middleware(['throttle:60,1']);
    Route::get('/user-analytics', [UserController::class, 'userAnalytics'])->middleware(['throttle:60,1']);



    Route::middleware(['throttle:60,1', 'OnlyCompany'])->get('get-company-consultancy-listing', [CompanyConsultancyController::class, 'getConsultancyListingByCompany']);   // Done By softtonia
    Route::middleware(['throttle:60,1', 'OnlyCompany'])->get('search-consultancy-by-id', [CompanyConsultancyController::class, 'searchConsultancyById']);  // Done By softtonia
    Route::middleware(['throttle:60,1', 'OnlyCompany'])->post('send-request-by-company-to-consultancy', [CompanyConsultancyController::class, 'sendRequestByCompanyToConsultancy']); // Done By softtonia
    Route::middleware(['throttle:60,1', 'OnlyConsultancy'])->get('get-all-consultancy-join-request-listing', [CompanyConsultancyController::class, 'getConsultancyAllJoinRequest']);  // Done By softtonia
    Route::middleware(['throttle:60,1', 'allowed_roles'])->post('accept-decline-company-request-by-consultancy', [CompanyConsultancyController::class, 'acceptDeclineCompanyRequestByConsultancy']); // Done By softtonia
    Route::middleware(['throttle:60,1', 'OnlyConsultancy'])->post('leave-the-comapny-by-consultancy', [CompanyConsultancyController::class, 'leaveTheComapnyByConsultancy']); // Done By softtonia
    Route::middleware(['throttle:60,1', 'OnlyConsultancy'])->get('get-consultancy-details-with-company', [CompanyConsultancyController::class, 'getConsultancyDetailsWithCompany']);  // Done By softtonia


    Route::middleware(['throttle:60,1', 'OnlyCompany'])->get('fetch-assigned-project-of-company', [CompanyProjectController::class, 'fetchAssignedProjectOfCompany']); // Done By softtonia
    Route::post('property-details-by-projectId', [UserController::class, 'propertyDetailsByProjectId'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'OnlyConsultancy'])->get('fetch-total-assigned-project-to-consultancy', [ConsultancyProjectController::class, 'fetchTotalAssignedProjectToConsultancy']);
    Route::get('fetch-consultancy-total-assigned-project', [ConsultancyProjectController::class, 'fetchConsultancyTotalAssignedProjects'])->middleware(['throttle:60,1']);
    Route::post('assign-project-to-agent-by-consultancy', [ConsultancyProjectController::class, 'assignProjectToAgentByConsultancy'])->middleware(['throttle:60,1']);
    Route::get('fetch-assigned-project-of-agent', [AgentProjectController::class, 'fetchAssignedProjectOfAgent'])->middleware(['throttle:60,1']);
    Route::get('fetch-agent-total-assigned-project', [AgentProjectController::class, 'fetchAgentTotalAssignedProject'])->middleware(['throttle:60,1']);
    Route::get('fetch-total-project-of-consultancy', [ConsultancyProjectController::class, 'fetchTotalProjectOfConsultancy'])->middleware(['throttle:60,1']);
    Route::post('view-project-details-of-consultancy', [ConsultancyProjectController::class, 'viewProjectDetailsOfConsultancy'])->middleware(['throttle:60,1']);
    Route::post('view-project-details-of-company', [CompanyProjectController::class, 'viewProjectDetailsOfCompany'])->middleware(['throttle:60,1']);
    Route::post('globle-search-engine', [SearchEngineController::class, 'globleSearchEngine'])->middleware(['throttle:60,1']);


    Route::prefix('search')->group(function () {
        // GET filter data by search result
        Route::get('/get-filterdata-by-search-result', [SearchEngineController::class, 'getFilterDataBySearchResult'])->middleware(['throttle:60,1']);

        // POST apply filters and get property list
        Route::post('/apply-filters', [SearchEngineController::class, 'applyFilters'])->middleware(['throttle:60,1']);
    });


    ############# new ########
    // 1. Global Search API
    Route::post('/global-search', [SearchEngineController::class, 'globalSearch'])->middleware(['throttle:60,1']);

    // 2. Global Filters API
    Route::post('/global-filters', [SearchEngineController::class, 'globalFilters'])->middleware(['throttle:60,1']);

    // 3. Apply Filter API
    Route::post('/apply-filter', [SearchEngineController::class, 'applyFilter'])->middleware(['throttle:60,1']);
    ############ end new #########

    Route::get('listing-of-all-projects', [UserController::class, 'listingOfAllProjects'])->middleware(['throttle:60,1']);

    Route::get('all-top-agent-listing', [UserController::class, 'allTopAgentListing'])->middleware(['throttle:60,1']);

    Route::get('listing-of-trending-project', [UserController::class, 'listingOfAllTrendingProject'])->middleware(['throttle:60,1']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-site-setting', [SiteSettingController::class, 'updateSiteSetting']);
    Route::get('site-setting', [SiteSettingController::class, 'siteSetting'])->middleware(['throttle:60,1']);
    Route::get('listing-of-property-with-project', [UserController::class, 'listingOfPropertyWithProject'])->middleware(['throttle:60,1']);


    Route::middleware(['throttle:60,1', 'allow.owner.role'])->get('owner-dashboard-analytics', [OwnerDashboardAnalyticsController::class, 'ownerDashboardAnalytics']);




    Route::get('property-listing-by-location', [UserController::class, 'propertyListingByLocation'])->middleware(['throttle:60,1']);




    Route::middleware(['throttle:60,1', 'admin.token'])->get('get-all-consultancy-listing', [UserController::class, 'allConsultancyListing']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin_or_consultancy'])->get('get-consultancy-agents/{id}', [UserController::class, 'getConsultancyAgents']);
    Route::middleware(['throttle:60,1', 'company.admin'])->get('get-all-consultancy-listing-by-company', [UserController::class, 'getAllConsultancyListingByCompany']); //Done By softtonia


    // User route will end from here
    Route::get('get-all-roles', [RoleController::class, 'getallrole'])->middleware(['throttle:60,1']);
    Route::get('get-default-roles', [RoleController::class, 'getDefaultRole'])->middleware(['throttle:60,1']);
    Route::post('/password/reset', [ResetPasswordController::class, 'reset'])->name('password.update')->middleware(['throttle:60,1']);
    Route::get('verify-email/{id}/{code}', [VerificationController::class, 'verifyEmail'])->name('verify-email')->middleware(['throttle:60,1']);



    // ========== Subscribe Emails Import ===============
    Route::post('insert-subscribe-email', [SubscribeController::class, 'insertSubscribeEmail'])->middleware(['throttle:60,1']);
    Route::get('listing-subscribe-email', [SubscribeController::class, 'listingOfSubscribedEmails'])->middleware(['admin.token', 'throttle:60,1']);
    Route::post('import-subscribed-emails', [SubscribeController::class, 'importSubscribedEmails'])->middleware(['admin.token', 'throttle:60,1']);

    // ========= Subscribe Emails Export ===============
    Route::get('/subscribed-emails/export/{format}', [SubscribeController::class, 'exportSubscribedEmails'])->name('subscribed_emails.export')->middleware(['admin.token', 'throttle:60,1']);

    // =======Error Log Listing=================
    Route::get('error-logs', [ErrorLogController::class, 'listErrorLogs'])->middleware(['api.token', 'throttle:60,1']);
    Route::get('error-logs/download/{file}', [ErrorLogController::class, 'downloadFile'])->middleware(['api.token', 'throttle:60,1']);
    // Single delete route
    Route::delete('/error-logs/delete/{fileName}', [ErrorLogController::class, 'deleteErrorLog'])->middleware(['api.token', 'throttle:60,1']);
    // Bulk delete route
    Route::post('/error-logs/bulk-delete', [ErrorLogController::class, 'bulkDeleteErrorLogs'])->middleware(['api.token', 'throttle:60,1']);


    // End Website Route


    // frontend site
    // =======Front Property Listing============

});
Route::post('admin/login', [AdminController::class, 'login'])->name('login')->middleware(['throttle:60,1']);

// admin route will start from here

Route::middleware(['throttle:60,1', 'admin.token'])->post('/profile/update', [AdminController::class, 'update']);
// Add other routes here if needed


// Route::middleware(['throttle:60,1','auth:sanctum'])->prefix('admin')->group(function () {
// dd(1);

Route::middleware(['validate.api.client'])->group(function () {

    Route::middleware(['admin'])->prefix('admin')->group(function () {
        Route::middleware(['throttle:60,1', 'token.expiration'])->group(function () {

            Route::middleware('admin.token')->get('/get-admin-profile', [Admincontroller::class, 'getAdminProfile']);
        });

        Route::middleware(['throttle:60,1', 'admin.token'])->post('/login-restricted', [Admincontroller::class, 'LoginActiveInactive']);
        Route::post('/user-bulk-delete', [Admincontroller::class, 'userAllRecordBulksDelete'])->middleware(['throttle:60,1']);

        Route::middleware(['throttle:60,1', 'admin.token'])->post('/mail-config', [MailConfigController::class, 'store']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/mail-config/{id}', [MailConfigController::class, 'update']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/get-mail-config', [MailConfigController::class, 'getMailConfig']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/active-mail-config', [MailConfigController::class, 'ActiveMailConfig']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/mail-config-delete/{id}', [MailConfigController::class, 'deleteMailConfig']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/bulk-mail-configs-delete', [MailConfigController::class, 'bulkDeleteMailConfigs']);
        Route::middleware(['throttle:60,1', 'admin.token'])->get('/search-mail-configs', [MailConfigController::class, 'searchMailConfigs']);


        Route::post('/create-role-prefix-repeater', [SystemController::class, 'CreateRolePrefixRepeater'])->middleware(['throttle:60,1']);
        Route::post('/get-role-prefix-repeater', [SystemController::class, 'GetRolePrefixRepeater'])->middleware(['throttle:60,1']);
        Route::post('/delete-role-prefix-repeater/{ic}', [SystemController::class, 'DeleteRolePrefixRepeater'])->middleware(['throttle:60,1']);
        Route::post('/update-role-prefix-repeater-by-id/{id}', [SystemController::class, 'UpdateRolePrefixRepeater'])->middleware(['throttle:60,1']);

        Route::post('role-create', [RoleController::class, 'createRole'])->middleware(['throttle:60,1']);
        Route::post('role-edit', [RoleController::class, 'editRole'])->middleware(['throttle:60,1']);
        Route::post('role-delete', [RoleController::class, 'deleteRole'])->middleware(['throttle:60,1']);
        Route::get('role-listing/{id?}', [RoleController::class, 'index'])->middleware(['throttle:60,1']); // Optional ID parameter
        Route::post('roles/bulk-delete', [RoleController::class, 'bulkDeleteRoles'])->middleware(['throttle:60,1']);
        Route::post('roles/search', [RoleController::class, 'searchRole'])->middleware(['throttle:60,1']);
    });

    // ======= Analytics =========
    Route::middleware(['throttle:60,1', 'admin.token'])->get('admin-dashboard-analytics', [AdminDashboardAnalyticsController::class, 'adminDashboardAnalytics']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('business-dashboard-analytics', [BusinessDashboardAnalyticsController::class, 'businessDashboardAnalytics']);
    Route::middleware(['throttle:60,1', 'allow.owner.role'])->get('owner-dashboard-analytics', [OwnerDashboardAnalyticsController::class, 'ownerDashboardAnalytics']);

    // =======Location============

    Route::get('/all-location-list', [LocationController::class, 'locationList'])->middleware(['throttle:60,1']);



    // custom field exaport / import

    Route::middleware(['throttle:60,1', 'admin.token'])->get('/export-custom-fields-csv', [CustomFieldExportImportController::class, 'exportToCsv']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/import-custom-fields-csv', [CustomFieldExportImportController::class, 'importFromCsv']);


    // custom field will end from here

    // Group Route will start from here
    Route::middleware(['throttle:60,1', 'admin.token'])->post('groups-create', [GroupController::class, 'createGroup']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('groups-update/{id}', [GroupController::class, 'updateGroup']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('groups-list', [GroupController::class, 'index']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('groups-delete/{id}', [GroupController::class, 'deleteGroup']);
    Route::get('/check-unique-group-name', [GroupController::class, 'checkUniqueGroupName'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('groups-bulk-delete', [GroupController::class, 'bulkDeleteGroups']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('groups-search', [GroupController::class, 'searchByGroupName']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/groups/import', [GroupController::class, 'importGroups']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/groups/export', [GroupController::class, 'exportGroups']);



    // Group Route will end from here

    // Permission Route will start from here
    Route::post('permissions-delete', [PermissionController::class, 'deletePermission'])->middleware(['throttle:60,1']);
    Route::get('permissions-listing', [PermissionController::class, 'index'])->middleware(['throttle:60,1']);
    Route::post('permissions/assign', [PermissionController::class, 'assignPermission'])->middleware(['throttle:60,1']);
    Route::post('role/assign', [Rolecontroller::class, 'assignRole'])->middleware(['throttle:60,1']);
    Route::post('remove/permission', [PermissionController::class, 'removePermission'])->middleware(['throttle:60,1']);
    Route::get('/role/{roleId}/permissions', [PermissionController::class, 'getPermissionsByRole'])->middleware(['throttle:60,1']);

    Route::post('assign-permissions', [PermissionController::class, 'assignDynamicPermissions'])->middleware(['throttle:60,1']);
    Route::get('/permissions/{role_id}', [PermissionController::class, 'getPermissionsByRole'])->middleware(['throttle:60,1']);
    Route::get('/model-names', [PermissionController::class, 'getModelNames'])->middleware(['throttle:60,1']);
    // Permission Route will end from here



    Route::middleware(['throttle:60,1'])->group(function () {
        Route::middleware('adminOrCurrentUser')->group(function () {
            Route::post('tickets-create', [TicketController::class, 'store']);
            Route::post('tickets-update', [TicketController::class, 'update']);
            Route::post('tickets-delete', [TicketController::class, 'destroy']);
            Route::post('tickets-bulk-delete', [TicketController::class, 'bulkDestroy']);
            Route::post('tickets-search', [TicketController::class, 'searchByTicketNumber']);
            Route::post('get-tickets-by-token', [TicketController::class, 'getTicketByToken']);
            Route::post('update-tickets-status', [TicketController::class, 'updateTicketStatus']);
            Route::get('tickets-response-list-history/{ticketId}', [TicketController::class, 'ticketResponseHistory']);
        });
        Route::middleware('allrole.token')->group(function () {
            Route::get('tickets-list', [TicketController::class, 'index']);
            Route::post('get-tickets-by-id', [TicketController::class, 'show']);
            Route::post('tickets/respond', [TicketController::class, 'respond']);
            Route::get('tickets-respond-list', [TicketController::class, 'respondlist']);
        });
        Route::middleware('admin.token')->group(function () {
            Route::post('tickets-status-create', [TicketStatusController::class, 'store']);
            Route::post('tickets-status-update', [TicketStatusController::class, 'update']);
            Route::post('tickets-status-delete', [TicketStatusController::class, 'destroy']);
            Route::post('tickets-status-bulk-delete', [TicketStatusController::class, 'bulkDelete']);
            Route::post('get-tickets-status-byid', [TicketStatusController::class, 'show']);
        });
        Route::get('tickets-status-list', [TicketStatusController::class, 'index']);
        Route::get('search-tickets-status-name', [TicketStatusController::class, 'searchTicketStatusName']);
        Route::middleware('admin.token')->group(function () {
            Route::post('tickets-department-create', [TicketDepartmentController::class, 'store']);
            Route::post('tickets-department-update', [TicketDepartmentController::class, 'update']);
            Route::get('search-tickets-department-list', [TicketDepartmentController::class, 'searchDepartment']);
            Route::post('tickets-department-delete', [TicketDepartmentController::class, 'destroy']);
            Route::post('get-tickets-department-byid', [TicketDepartmentController::class, 'show']);
            Route::post('tickets-department-bulk-delete', [TicketDepartmentController::class, 'bulkDestroy']);
        });
        Route::get('tickets-department-list', [TicketDepartmentController::class, 'index']);

        Route::middleware('admin.token')->group(function () {
            Route::post('tickets-priority-create', [TicketPriorityController::class, 'store']);
            Route::post('tickets-priority-update', [TicketPriorityController::class, 'update']);
            Route::get('tickets-priority-list', [TicketPriorityController::class, 'index']);
            Route::post('tickets-priority-delete', [TicketPriorityController::class, 'destroy']);
            Route::post('tickets-priority-bulk-delete', [TicketPriorityController::class, 'bulkDelete']);
            Route::post('get-tickets-priority-byid', [TicketPriorityController::class, 'show']);
            Route::get('search-tickets-priority', [TicketPriorityController::class, 'searchTicketPriority']);
        });
        Route::middleware('admin.token')->group(function () {
            Route::post('tickets-type-create', [TicketTypeController::class, 'store']);
            Route::post('tickets-type-update', [TicketTypeController::class, 'update']);
            Route::post('tickets-type-delete', [TicketTypeController::class, 'destroy']);
            Route::delete('tickets-type-bulk-delete', [TicketTypeController::class, 'bulkDelete']);
        });
        Route::get('tickets-type-list', [TicketTypeController::class, 'index']);
        Route::post('get-tickets-type-byid', [TicketTypeController::class, 'show']);
        Route::get('search-tickets-type', [TicketTypeController::class, 'searchTicketType']);
    });

    // Agent Route will start from here
    Route::post('agent-store', [AgentController::class, 'store'])->middleware(['throttle:60,1']);
    Route::post('agent-update', [AgentController::class, 'update'])->middleware(['throttle:60,1']);
    Route::post('agent', [AgentController::class, 'destroy'])->middleware(['throttle:60,1']);
    Route::post('agents/toggle-status', [AgentController::class, 'toggleStatus'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'consultancy.role'])->post('send-request-by-consultancy-to-agent', [AgentController::class, 'sendRequestByConsultancyToAgent']);
    Route::post('accept-decline-request-by-consultancy-to-agent', [AgentController::class, 'AcceptDeclineRequestByConsultancyToAgent'])->middleware(['throttle:60,1']);
    Route::post('leave-the-consultancy', [AgentController::class, 'leaveTheConsultancy'])->middleware(['throttle:60,1']);
    Route::post('get-agent-details', [AgentController::class, 'getAgentDetails'])->middleware(['throttle:60,1']);
    Route::get('get-all-join-request-listing', [AgentController::class, 'getAllJoinRequestList'])->middleware(['throttle:60,1']);
    Route::get('get-consultancy-details', [AgentController::class, 'getConsultancyDetails'])->middleware(['throttle:60,1']);
    Route::post('create-agent', [UserController::class, 'createAgent'])->middleware(['throttle:60,1']);
    Route::get('get-consultancy-agent-listing', [AgentController::class, 'getConsultancyAgentListing'])->middleware(['throttle:60,1']);
    Route::post('search-agent-by-id', [AgentController::class, 'searchAgentByID'])->middleware(['throttle:60,1']);

    // consultancy to company routes
    Route::post('assign-project-to-consultancy-by-company', [UserController::class, 'assignProjectToConsultancyByCompany'])->middleware(['throttle:60,1']);


    // Agent Route will end from here

    // Media Route will start from here
    Route::middleware(['throttle:60,1', 'admin.token'])->post('media/add', [MediaController::class, 'addMedia']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('media/update', [MediaController::class, 'updateMedia']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('media', [MediaController::class, 'deleteMedia']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('media-list', [MediaController::class, 'index']);
    // Media Route will end from here


    // =========Page=======

    // =========16-07-2025=======

    Route::middleware(['throttle:60,1', 'admin.token'])->get('get-all-pages-list', [PageController::class, 'index']);
    Route::get('get-pages-by-id', [PageController::class, 'show'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('create-pages', [PageController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-pages-by-id/{id}', [PageController::class, 'update']);
    Route::middleware(['throttle:60,1', 'admin.token'])->delete('delete-pages-by-id/{id}', [PageController::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('bulk-delete-pages', [PageController::class, 'bulkDestroy']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('search-pages', [PageController::class, 'searchPage']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('check-unique-pages', [PageController::class, 'checkUnique']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-page-status/{id}', [PageController::class, 'updatePageStatus']);





    // =========About us========

    Route::get('/about-us', [AboutUsController::class, 'show'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/about-us', [AboutUsController::class, 'storeOrUpdate']);


    // =========Property Valuation========
    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-valuation-update', [PropertyValuationController::class, 'update']);
    Route::get('property-valuation-list', [PropertyValuationController::class, 'index'])->middleware(['throttle:60,1']);


    // =========Help Cat========
    Route::get('help-category-list', [HelpCategoryController::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-category-create', [HelpCategoryController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-category-update', [HelpCategoryController::class, 'update']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-category-delete', [HelpCategoryController::class, 'delete']);
    Route::get('get-help-category-by-id/{id}', [HelpCategoryController::class, 'getdatabyId'])->middleware(['throttle:60,1']);
    Route::get('search-help-category-list', [HelpCategoryController::class, 'searchByName'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-category-bulk-delete', [HelpCategoryController::class, 'bulkDelete']);


    // ==========Help Subcat=======
    Route::get('help-subcategory-list', [HelpSubcategoryController::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-subcategory-create', [HelpSubcategoryController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-subcategory-update', [HelpSubcategoryController::class, 'update']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-subcategory-delete', [HelpSubcategoryController::class, 'delete']);
    Route::get('get-help-subcategory-by-id/{id}', [HelpSubcategoryController::class, 'getdatabyId'])->middleware(['throttle:60,1']);
    Route::get('search-help-subcategory-list', [HelpSubcategoryController::class, 'searchByName'])->middleware(['throttle:60,1']);
    Route::post('help-subcategory-by-categoryid', [HelpSubcategoryController::class, 'getHelpSubcategoryByCategoryId'])->middleware(['throttle:60,1']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-subcategory-bulk-delete', [HelpSubcategoryController::class, 'bulkDelete']);

    // ===========Help Childcat=======
    Route::get('help-childcategory-list', [HelpChildcategoryController::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-childcategory-create', [HelpChildcategoryController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-childcategory-update', [HelpChildcategoryController::class, 'update']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-childcategory-delete', [HelpChildcategoryController::class, 'delete']);
    Route::get('get-help-childcategory-by-id/{id}', [HelpChildcategoryController::class, 'getdatabyId'])->middleware(['throttle:60,1']);
    Route::get('search-help-childcategory-list', [HelpChildcategoryController::class, 'searchByName'])->middleware(['throttle:60,1']);
    Route::post('help-childcategory-by-subcategoryid', [HelpChildcategoryController::class, 'getHelpChildcategoryBySubcategoryId'])->middleware(['throttle:60,1']);




    // =========Help Art=======
    Route::get('help-article-list', [HelpArticleController::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-article-create', [HelpArticleController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-article-update', [HelpArticleController::class, 'update']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-article-delete', [HelpArticleController::class, 'delete']);
    Route::get('get-help-article-by-id/{id}', [HelpArticleController::class, 'getdatabyId'])->middleware(['throttle:60,1']);
    Route::get('search-help-article-list', [HelpArticleController::class, 'searchByTitle'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('help-article-bulk-delete', [HelpArticleController::class, 'bulkDelete']);
    Route::get('get-help-article', [HelpArticleController::class, 'getArticles'])->middleware(['throttle:60,1']);


    // ==========Like/Dislike===============


    Route::post('/help-activity', [HelpActivityController::class, 'manageActivity'])->middleware(['throttle:60,1']);

    // =========Services=======
    Route::get('services-list', [servicescontroller::class, 'index'])->middleware(['throttle:60,1']);
    Route::post('services-create', [servicescontroller::class, 'store'])->middleware(['throttle:60,1']);
    Route::post('services-update', [servicescontroller::class, 'update'])->middleware(['throttle:60,1']);
    Route::post('services', [servicescontroller::class, 'delete'])->middleware(['throttle:60,1']);


    // =========Profile=======
    Route::post('complete-your-profile', [Profilecontroller::class, 'updateProfile'])->middleware(['throttle:60,1']);


    // =====For Client Review=====
    Route::middleware(['throttle:60,1', 'api.token'])->post('add-client-review', [ClientReviewController::class, 'store']);
    Route::middleware(['throttle:60,1', 'api.token'])->post('edit-client-review', [ClientReviewController::class, 'update']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('delete-client-review', [ClientReviewController::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('bulk-delete-client-review', [ClientReviewController::class, 'bulkDelete']);
    Route::get('get-client-review', [ClientReviewController::class, 'index'])->middleware(['throttle:60,1']);
    Route::get('search-client-review', [ClientReviewController::class, 'searchByTitle'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('get-client-review-by-id/{id}', [ClientReviewController::class, 'getdatabyId']);

    // =====For Faq Category=====
    Route::middleware(['throttle:60,1', 'admin.token'])->post('add-faq-category', [FaqCategoryController::class, 'store']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('edit-faq-category', [FaqCategoryController::class, 'update']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('delete-faq-category', [FaqCategoryController::class, 'destroy']); //Done By softtonia
    Route::get('get-faq-category', [FaqCategoryController::class, 'index'])->middleware(['throttle:60,1']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->get('get-faq-category-by-id/{id}', [FaqCategoryController::class, 'getdatabyId']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('bulk-delete-faq-category', [FaqCategoryController::class, 'bulkDelete']);

    // =====For Faq =======
    Route::middleware(['throttle:60,1', 'admin.token'])->post('add-faq', [FaqController::class, 'store']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('edit-faq', [FaqController::class, 'update']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('delete-faq', [FaqController::class, 'destroy']); //Done By softtonia
    Route::get('get-faq', [FaqController::class, 'index'])->middleware(['throttle:60,1']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->get('get-faq-by-id/{id}', [FaqController::class, 'getdatabyId']); //Done By softtonia

    // Otp Route
    Route::middleware(['throttle:60,1', 'validate.api.client'])->post('/verify-email-otp', [EmailOtpController::class, 'verifyOtp']);
    Route::middleware(['throttle:60,1', 'validate.api.client'])->get('/resend-email-otp', [OtpController::class, 'resendOtp']);




    // With otp password forget
    Route::post('/generate-email-otp', [EmailOtpController::class, 'generateOtp'])->middleware(['throttle:60,1']);
    Route::post('/reset-password', [EmailOtpController::class, 'resetPassword'])->middleware(['throttle:60,1']);

    // Country, State, City Get

    Route::get('countries', [LocationController::class, 'getCountries'])->middleware(['throttle:60,1']);
    Route::get('states/{countryId}', [LocationController::class, 'getStatesByCountry'])->middleware(['throttle:60,1']);
    Route::get('cities/{stateId}', [LocationController::class, 'getCitiesByState'])->middleware(['throttle:60,1']);

    Route::middleware(['throttle:60,1', 'admin.token'])->get('/get-location-countries', [LocationController::class, 'getLocationCountries']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/get-location-states', [LocationController::class, 'getLocationStates']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/get-location-cities', [LocationController::class, 'getLocationCities']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/search-all-locations', [LocationController::class, 'searchAllLocations']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/search-countries-location', [LocationController::class, 'searchCountries']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/search-states-location', [LocationController::class, 'searchStates']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/search-cities-location', [LocationController::class, 'searchCities']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('/cities/{id}/update-flags', [LocationController::class, 'updateCityFlags']);

    Route::middleware(['throttle:60,1', 'admin.token'])->get('/export-location-csv', [LocationController::class, 'locationExportToCSV']);





    Route::middleware(['throttle:60,1', 'allrole.token'])->post('business-role-update-profile', [UserController::class, 'updateProfile']);






    // Template  Id




    // Top Features for project developer and listings
    Route::get('top-features-list', [TopFeatureController::class, 'index'])->middleware(['throttle:60,1']);
    Route::get('/get-top-features-by-id', [TopFeatureController::class, 'getTopFeaturesById'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('create-or-update-top-feature/{id?}', [TopFeatureController::class, 'createOrUpdateTopFeature']);

    // Menu Managements

    Route::prefix('menus')->group(function () {
        Route::get('/', [MenuController::class, 'index'])->middleware(['throttle:60,1']);
        Route::get('/show/{id}', [MenuController::class, 'show'])->middleware(['throttle:60,1']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/store', [MenuController::class, 'store']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/update/{id}', [MenuController::class, 'update']);
        Route::middleware(['throttle:60,1', 'admin.token'])->delete('/delete/{id}', [MenuController::class, 'destroy']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/reorder', [MenuController::class, 'reorder']); // Nested reorder
    });


    // Connection lifecycle
    Route::post('/connections', [ConnectionController::class, 'store'])->middleware(['throttle:60,1']);         // Send connection request
    Route::post('/connections/{connection}/accept', [ConnectionController::class, 'accept'])->middleware(['throttle:60,1']);   // Accept
    Route::post('/connections/{connection}/reject', [ConnectionController::class, 'reject'])->middleware(['throttle:60,1']);   // Reject
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->middleware(['throttle:60,1']);       // Cancel or Leave

    // Associations (connected users by role)
    Route::get('/my/associations', [UserAssociationController::class, 'associations'])->middleware(['throttle:60,1']);
    Route::get('/my/consultancies', [UserAssociationController::class, 'consultancies'])->middleware(['throttle:60,1']);
    Route::get('/my/companies', [UserAssociationController::class, 'companies'])->middleware(['throttle:60,1']);
    Route::get('/my/agents', [UserAssociationController::class, 'agents'])->middleware(['throttle:60,1']);
    Route::get('/my/developers', [UserAssociationController::class, 'developers'])->middleware(['throttle:60,1']);


    // Get all leads
    Route::post('/leads/send-otp', [LeadController::class, 'sendOtp'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/leads', [LeadController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/leads-by-admin', [LeadController::class, 'storeByAdmin']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/leads/{id}', [LeadController::class, 'show']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/leads/update/{id}', [LeadController::class, 'update']);
    Route::middleware(['throttle:60,1', 'admin.token'])->delete('/leads/{id}', [LeadController::class, 'destroy']);

    Route::get('/get-assign-lead-to-user', [LeadController::class, 'assignUserLead'])->middleware(['throttle:60,1']);

    // Lead Types

    Route::get('/lead-types', [LeadTypeController::class, 'index'])->middleware(['throttle:60,1']);     // Get all
    Route::get('/lead-types/check-slug-unique', [LeadTypeController::class, 'checkSlugUnique'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/lead-types/search', [LeadTypeController::class, 'searchLeadType']);
    Route::get('/lead-types/{id}', [LeadTypeController::class, 'show'])->middleware(['throttle:60,1']); // Get single
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/lead-types', [LeadTypeController::class, 'store']);    // Create
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/lead-types-update/{id}', [LeadTypeController::class, 'update']); // Update
    Route::middleware(['throttle:60,1', 'admin.token'])->delete('/lead-types/{id}', [LeadTypeController::class, 'destroy']); // Delete
    Route::post('/lead-types/search-by-name', [LeadTypeController::class, 'getSearchByName'])->middleware(['throttle:60,1']);
    Route::post('/lead-types/search-by-slug', [LeadTypeController::class, 'getSearchBySlug'])->middleware(['throttle:60,1']);


    Route::middleware(['throttle:60,1', 'admin.token'])->get('contact-us-leads', [ContactUsLeadController::class, 'index']);   // List with pagination
    Route::post('contact-us-leads', [ContactUsLeadController::class, 'store'])->middleware(['throttle:60,1']); // Create
    Route::middleware(['throttle:60,1', 'admin.token'])->get('contact-us-leads/{id}', [ContactUsLeadController::class, 'show']); // Show single
    Route::middleware(['throttle:60,1', 'admin.token'])->put('contact-us-leads/{id}', [ContactUsLeadController::class, 'update']); // Update
    Route::middleware(['throttle:60,1', 'admin.token'])->delete('contact-us-leads/{id}', [ContactUsLeadController::class, 'destroy']); // Delete
    Route::middleware(['throttle:60,1', 'admin.token'])->post('contact-us-leads/bulk-delete', [ContactUsLeadController::class, 'bulkDestroy']); // Delete
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/contact-us-leads/{id}/status', [ContactUsLeadController::class, 'updateStatus']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('contact-us-leads/search', [ContactUsLeadController::class, 'contactUsLeadSearch']);
});



Route::get('/developer-listings-by-featured-type', [TopFeatureController::class, 'getDevelopersByFeaturedType'])->middleware(['throttle:60,1']);
Route::get('/properties-listing-by-featured-type', [TopFeatureController::class, 'getPropertiesByFeaturedType'])->middleware(['throttle:60,1']);
Route::get('/project-listings-by-featured-type', [TopFeatureController::class, 'getProjectsByFeaturedType'])->middleware(['throttle:60,1']);

// API Client

Route::middleware((['admin.token']))->get('api-client-secrect-list', [ApiClientController::class, 'index']);
Route::middleware('admin.token')->post('api-client-secrect-store', [ApiClientController::class, 'store']);
Route::middleware((['admin.token']))->get('api-client-secrect-show-by-id/{id}', [ApiClientController::class, 'show']);
Route::middleware('admin.token')->post('api-client-secrect-update/{id}', [ApiClientController::class, 'update']);
Route::middleware('admin.token')->post('api-client-secrect-delete/{id}', [ApiClientController::class, 'destroy']);

Route::middleware('admin.token')->get('generate-api-client-id', [ApiClientController::class, 'generateApiClientId']);
Route::middleware('admin.token')->get('generate-api-client-secret', [ApiClientController::class, 'generateApiClientSecret']);
Route::middleware('admin.token')->get('generate-next-js-internal-key', [ApiClientController::class, 'generateNextJsInternalKey']);
Route::middleware('admin.token')->get('api-client-secrect-app-types', [ApiClientController::class, 'getAppTypes']);

Route::middleware('admin.token')->get('api-client-secrect-show-by-app-types/{appType}', [ApiClientController::class, 'showByAppType']);
Route::middleware('admin.token')->get('api-client-secrect-export-csv/{id}', [ApiClientController::class, 'exportCsvApiClient']);


// New Secure Application Password Routes
Route::middleware(['throttle:60,1', 'admin.token'])
    ->prefix('admin')
    ->group(function () {
        Route::get('api-clients/available-permissions', [ApiClientController::class, 'availablePermissions']);

        Route::apiResource('api-clients', ApiClientController::class)
            ->parameters([
                'api-clients' => 'apiClient',
            ]);

        Route::get('api-clients/{apiClient}/application-passwords', [ApplicationPasswordController::class, 'index']);
        Route::post('api-clients/{apiClient}/application-passwords', [ApplicationPasswordController::class, 'store']);
        Route::delete('api-clients/{apiClient}/application-passwords/{applicationPassword}', [ApplicationPasswordController::class, 'destroy']);
        Route::post('api-clients/{apiClient}/application-passwords/{applicationPassword}/rotate', [ApplicationPasswordController::class, 'rotate']);

        Route::get('blocked-api-ips', [BlockedApiIpController::class, 'index']);
        Route::post('blocked-api-ips', [BlockedApiIpController::class, 'store']);
        Route::delete('blocked-api-ips/{blockedApiIp}', [BlockedApiIpController::class, 'destroy']);

        Route::get('api-auth-failures', [ApiAuthFailureController::class, 'index']);
        Route::get('api-auth-failures/reasons', [ApiAuthFailureController::class, 'reasons']);
        Route::get('api-auth-failures/top-ips', [ApiAuthFailureController::class, 'topIps']);
    });

// Route::prefix('v1')
//     ->middleware([
//         'validate.api.client',
//         'app.blocked_ip',
//         'app.password',
//         'app.origin',
//         'app.signature',
//         'app.rate',
//         'app.log',
//     ])
//     ->group(function () {
//         Route::get('secure-test', function () {
//             $client = request()->attributes->get('api_client');

//             return response()->json([
//                 'success' => true,
//                 'message' => 'Secure API access granted.',
//                 'client' => [
//                     'id' => $client->id,
//                     'name' => $client->name,
//                     'type' => $client->type,
//                 ],
//             ]);
//         })->middleware('client.permission:*');

//         Route::prefix('post-types')->group(function () {
//             Route::get('/', [PostTypeController::class, 'index']);

//             Route::get('{postType:slug}', [PostTypeController::class, 'show']);

//             Route::get('{postType:slug}/posts', [DynamicPostController::class, 'index']);

//             Route::get('{postType:slug}/posts/{dynamicPost}', [DynamicPostController::class, 'show']);

//             Route::post('{postType:slug}/posts', [DynamicPostController::class, 'store']);

//             Route::put('{postType:slug}/posts/{dynamicPost}', [DynamicPostController::class, 'update']);

//             Route::delete('{postType:slug}/posts/{dynamicPost}', [DynamicPostController::class, 'destroy']);
//         });
//     });

// IpLog

Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs', [IpLogController::class, 'index']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('admin/ip-logs-update-status', [IpLogController::class, 'updateIpStatus']);

Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs-by-ip-address', [IpLogController::class, 'getByIpAddress']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs-by-user-id', [IpLogController::class, 'getByUserId']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs-by-id', [IpLogController::class, 'getById']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('admin/ip-logs-update-status-by-ip', [IpLogController::class, 'updateStatusByIp']);


Route::middleware(['throttle:60,1', 'admin.token'])->get('/business-enquiries', [BusinessEnquiryController::class, 'index']);
Route::post('/business-enquiries', [BusinessEnquiryController::class, 'store'])->middleware(['throttle:60,1']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('/business-enquiries/{id}', [BusinessEnquiryController::class, 'show']);
Route::middleware(['throttle:60,1', 'admin.token'])->delete('/business-enquiries/{id}', [BusinessEnquiryController::class, 'destroy']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('/business-enquiries/bulk-delete', [BusinessEnquiryController::class, 'bulkDelete']);




Route::middleware(['throttle:60,1'])->group(function () {
    Route::get(
        'auth/google',
        [GoogleAuthController::class, 'redirectToGoogle']
    );

    Route::get(
        'auth/google/callback',
        [GoogleAuthController::class, 'handleGoogleCallback']
    );

    Route::post(
        'auth/google/exchange',
        [GoogleAuthController::class, 'exchangeGoogleLoginCode']
    );

    Route::post(
        'auth/google/complete-registration',
        [GoogleAuthController::class, 'completeGoogleRegistration']
    );
});
// ================= VK Admin CRM Builder APIs =================
Route::middleware(['throttle:60,1', 'admin.token', 'validate.api.client'])->group(function () {


    // Templates
    Route::get('template-dynamic-fields', [TemplateDynamicFieldController::class, 'index']);
    Route::get('template-options', [TemplateController::class, 'options']);
    Route::get('template-shortcodes', [TemplateController::class, 'shortcodes']);

    Route::get('templates-list', [TemplateController::class, 'index']);
    Route::post('templates-create', [TemplateController::class, 'create']);
    Route::get('templates-show/{id}', [TemplateController::class, 'show'])->whereNumber('id');
    Route::post('templates-update/{id}', [TemplateController::class, 'update'])->whereNumber('id');
    Route::post('templates-update-status/{id}', [TemplateController::class, 'updateStatus'])->whereNumber('id');
    Route::delete('templates-delete/{id}', [TemplateController::class, 'destroy'])->whereNumber('id');

    Route::get('template-export/{id}', [TemplateExportImportController::class, 'export'])->whereNumber('id');
    Route::post('template-import', [TemplateExportImportController::class, 'import']);

    Route::get('template-revisions/{template_id}', [TemplateRevisionController::class, 'index'])->whereNumber('template_id');
    Route::get('template-revisions/{template_id}/{revision_id}', [TemplateRevisionController::class, 'show'])
        ->whereNumber('template_id')
        ->whereNumber('revision_id');
    Route::post('template-revisions/{template_id}/{revision_id}/restore', [TemplateRevisionController::class, 'restore'])
        ->whereNumber('template_id')
        ->whereNumber('revision_id');

    Route::get('template-publish-validate/{id}', [TemplatePublishValidationController::class, 'check'])->whereNumber('id');
    Route::post('template-duplicate/{id}', [TemplateDuplicateController::class, 'duplicate'])->whereNumber('id');

    Route::get('template-trash', [TemplateTrashController::class, 'trashed']);
    Route::post('template-bulk-trash', [TemplateTrashController::class, 'bulkTrash']);
    Route::post('template-bulk-restore', [TemplateTrashController::class, 'bulkRestore']);
    Route::post('template-bulk-force-delete', [TemplateTrashController::class, 'bulkForceDelete']);
    Route::post('template-empty-trash', [TemplateTrashController::class, 'emptyTrash']);

    Route::get('template-conflicts/{id}', [TemplateConflictController::class, 'check'])->whereNumber('id');
    Route::post('template-trash/{id}', [TemplateTrashController::class, 'trash'])->whereNumber('id');
    Route::post('template-restore/{id}', [TemplateTrashController::class, 'restore'])->whereNumber('id');
    Route::delete('template-force-delete/{id}', [TemplateTrashController::class, 'forceDelete'])->whereNumber('id');

    Route::post('template-preview/{template_id}', [TemplatePreviewController::class, 'preview'])->whereNumber('template_id');
    Route::post('page-builder/context', [PageBuilderContextController::class, 'resolve']);

    // Template Display Conditions
    Route::get('template-conditions-list/{template_id}', [TemplateDisplayConditionController::class, 'index'])->whereNumber('template_id');
    Route::post('template-conditions-replace', [TemplateDisplayConditionController::class, 'replace']);
    Route::post('template-conditions-create', [TemplateDisplayConditionController::class, 'create']);
    Route::post('template-conditions-update', [TemplateDisplayConditionController::class, 'update']);
    Route::delete('template-conditions-delete/{id}', [TemplateDisplayConditionController::class, 'destroy'])->whereNumber('id');

    // Template Builder Layout
    Route::get('template-builder-show/{template_id}', [TemplateBuilderController::class, 'show'])->whereNumber('template_id');
    Route::post('template-builder-save/{template_id}', [TemplateBuilderController::class, 'save'])->whereNumber('template_id');

    // Post Types
    Route::get('post-types-support-options', [PostTypeController::class, 'supportOptions']);
    Route::get('post-types/trash', [PostTypeController::class, 'trash']);
    Route::post('post-types/bulk-delete', [PostTypeController::class, 'bulkDelete']);
    Route::post('post-types/bulk-restore', [PostTypeController::class, 'bulkRestore']);
    Route::delete('post-types/bulk-force-delete', [PostTypeController::class, 'bulkForceDelete']);
    Route::get('post-types-menu', [PostTypeController::class, 'menu']);

    Route::get('post-types/export-csv', [PostTypeExportImportController::class, 'exportToCsv']);
    Route::post('post-types/import-csv', [PostTypeExportImportController::class, 'importFromCsv']);

    Route::post('post-types/{id}/restore', [PostTypeController::class, 'restore'])->whereNumber('id');
    Route::delete('post-types/{id}/force-delete', [PostTypeController::class, 'forceDelete'])->whereNumber('id');
    Route::get('post-types/{postType}/fields', [PostTypeController::class, 'fields']);

    Route::get('post-types', [PostTypeController::class, 'index']);
    Route::post('post-types', [PostTypeController::class, 'store']);
    Route::get('post-types/{postType}', [PostTypeController::class, 'show']);
    Route::put('post-types/{postType}', [PostTypeController::class, 'update']);
    Route::delete('post-types/{postType}', [PostTypeController::class, 'destroy']);

    // Dynamic Posts
    Route::get('dynamic-posts/dropdown', [DynamicPostController::class, 'dropdownByPostType']);
    Route::get('dynamic-posts/by-post-type/{slug}', [DynamicPostController::class, 'byPostType']);
    Route::post('dynamic-posts/bulk-delete', [DynamicPostController::class, 'bulkDelete']);

    Route::get('dynamic-post-form/{postType}', [DynamicPostController::class, 'formOptions']);
    Route::post('resolve-custom-fields', [DynamicPostController::class, 'resolveCustomFieldsForCreate']);
    Route::get('custom-fields', [DynamicPostController::class, 'customFieldsByPostType']);
    Route::get('dynamic-post-keyword-suggestions', [DynamicPostController::class, 'keywordSuggestions']);
    // Assignment dropdown APIs
    Route::get('dynamic-post-assignment/users', [DynamicPostController::class, 'assignmentUserDropdown']);
    Route::get('dynamic-post-assignment/roles', [DynamicPostController::class, 'assignmentRoleDropdown']);


    Route::get('dynamic-posts', [DynamicPostController::class, 'index']);
    Route::post('dynamic-posts', [DynamicPostController::class, 'store']);
    Route::get('dynamic-posts/template-csv', [DynamicPostCsvController::class, 'template']);
    Route::get('dynamic-posts/export-csv', [DynamicPostCsvController::class, 'export']);
    Route::post('dynamic-posts/import-csv', [DynamicPostCsvController::class, 'import']);
    Route::get('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'show'])->whereNumber('dynamicPost');
    Route::put('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'update'])->whereNumber('dynamicPost');
    Route::delete('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'destroy'])->whereNumber('dynamicPost');

    // Taxonomies
    Route::get('taxonomies', [TaxonomyController::class, 'index']);
    Route::post('taxonomies', [TaxonomyController::class, 'store']);
    Route::get('taxonomies-tree', [TaxonomyController::class, 'tree']);
    Route::get('taxonomies/export-csv', [TaxonomyExportImportController::class, 'exportToCsv']);
    Route::post('taxonomies/import-csv', [TaxonomyExportImportController::class, 'importFromCsv']);
    Route::get('taxonomies/trash', [TaxonomyController::class, 'trash']);
    Route::post('taxonomies/bulk-delete', [TaxonomyController::class, 'bulkDelete']);
    Route::post('taxonomies/bulk-restore', [TaxonomyController::class, 'bulkRestore']);
    Route::post('taxonomies/bulk-force-delete', [TaxonomyController::class, 'bulkForceDelete']);

    Route::get('taxonomies/{taxonomy}/terms', [TaxonomyController::class, 'terms']);
    Route::get('taxonomies/{taxonomy}/fields', [TaxonomyController::class, 'fields']);
    Route::post('taxonomies/{id}/restore', [TaxonomyController::class, 'restore'])->whereNumber('id');
    Route::delete('taxonomies/{id}/force-delete', [TaxonomyController::class, 'forceDelete'])->whereNumber('id');

    Route::get('taxonomies/{taxonomy}', [TaxonomyController::class, 'show']);
    Route::put('taxonomies/{taxonomy}', [TaxonomyController::class, 'update']);
    Route::delete('taxonomies/{taxonomy}', [TaxonomyController::class, 'destroy']);

    // Taxonomy Terms
    Route::post('taxonomy-terms/bulk-delete', [TaxonomyTermController::class, 'bulkDelete']);
    Route::get('term-relations/taxonomies', [TaxonomyTermController::class, 'relationTaxonomies']);
    Route::get('relation-taxonomies/{taxonomy}/terms', [TaxonomyTermController::class, 'relationValues']);

    Route::get('taxonomy-terms', [TaxonomyTermController::class, 'index']);
    Route::post('taxonomy-terms', [TaxonomyTermController::class, 'store']);
    Route::get('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'show'])->whereNumber('taxonomyTerm');
    Route::put('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'update'])->whereNumber('taxonomyTerm');
    Route::delete('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'destroy'])->whereNumber('taxonomyTerm');

    // Post Taxonomy Terms
    Route::post('post-taxonomy-terms/sync', [PostTaxonomyTermController::class, 'sync']);
    Route::post('post-taxonomy-terms/bulk-delete', [PostTaxonomyTermController::class, 'bulkDelete']);

    Route::get('post-taxonomy-terms', [PostTaxonomyTermController::class, 'index']);
    Route::post('post-taxonomy-terms', [PostTaxonomyTermController::class, 'store']);
    Route::get('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'show'])->whereNumber('postTaxonomyTerm');
    Route::put('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'update'])->whereNumber('postTaxonomyTerm');
    Route::delete('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'destroy'])->whereNumber('postTaxonomyTerm');

    // Custom Field Groups
    Route::get('custom-field-groups/export-csv', [CustomFieldGroupExportImportController::class, 'exportToCsv']);
    Route::post('custom-field-groups/import-csv', [CustomFieldGroupExportImportController::class, 'importFromCsv']);
    Route::post('custom-field-groups-bulk-delete', [CustomFieldGroupController::class, 'bulkDelete']);

    Route::get('custom-field-groups', [CustomFieldGroupController::class, 'index']);
    Route::post('custom-field-groups', [CustomFieldGroupController::class, 'store']);
    Route::get('custom-field-groups/{id}', [CustomFieldGroupController::class, 'show'])->whereNumber('id');
    Route::put('custom-field-groups/{id}', [CustomFieldGroupController::class, 'update'])->whereNumber('id');
    Route::delete('custom-field-groups/{id}', [CustomFieldGroupController::class, 'destroy'])->whereNumber('id');

    // Custom Fields
    Route::get('custom-fields-paginated', [CustomFieldGroupController::class, 'fieldsIndex']);
    Route::post('custom-fields/bulk-delete', [CustomFieldGroupController::class, 'bulkDeleteFields']);

    Route::get('custom-fields/{fieldId}', [CustomFieldGroupController::class, 'showFieldById'])->whereNumber('fieldId');
    Route::match(['put', 'patch'], 'custom-fields/{fieldId}', [CustomFieldGroupController::class, 'updateFieldById'])->whereNumber('fieldId');
    Route::delete('custom-fields/{fieldId}', [CustomFieldGroupController::class, 'destroyFieldById'])->whereNumber('fieldId');

    Route::post('custom-field-groups/{groupId}/fields', [CustomFieldGroupController::class, 'storeField'])->whereNumber('groupId');
    Route::put('custom-field-groups/{groupId}/fields/{fieldId}', [CustomFieldGroupController::class, 'updateField'])
        ->whereNumber('groupId')
        ->whereNumber('fieldId');
    Route::delete('custom-field-groups/{groupId}/fields/{fieldId}', [CustomFieldGroupController::class, 'destroyField'])
        ->whereNumber('groupId')
        ->whereNumber('fieldId');
    Route::post('custom-field-groups/{groupId}/fields/reorder', [CustomFieldGroupController::class, 'reorderFields'])->whereNumber('groupId');

    // Dropdown/List APIs
    Route::get('post-types-list', [CustomFieldGroupController::class, 'postTypesList']);
    Route::get('taxonomies-list', [CustomFieldGroupController::class, 'taxonomiesList']);
    Route::get('taxonomy-terms-list/{taxonomyId}', [CustomFieldGroupController::class, 'taxonomyTermsList'])->whereNumber('taxonomyId');

    Route::get('custom-field-groups-by-post-type/{postType}', [CustomFieldGroupController::class, 'groupsByPostType']);
    Route::post('custom-field-groups-by-taxonomy/{taxonomy}', [CustomFieldGroupController::class, 'groupsByTaxonomy']);

    // Page Builder
    Route::prefix('page-builder')->group(function () {
        Route::get('/widgets', [WidgetApiController::class, 'index']);
        Route::get('/dynamic-fields', [DynamicFieldApiController::class, 'index']);
        Route::get('/widgets/{type}', [WidgetApiController::class, 'show']);
    });
    // Keywords custom routes should come before apiResource
    Route::get('keywords-list', [KeywordController::class, 'index']);
    Route::post('keywords-create', [KeywordController::class, 'store']);
    Route::get('keywords-show/{id}', [KeywordController::class, 'show']);
    Route::post('keywords-update/{id}', [KeywordController::class, 'update']);
    Route::delete('keywords-delete/{id}', [KeywordController::class, 'destroy']);
    Route::post('keywords-status/{id}', [KeywordController::class, 'changeStatus']);
    Route::post('keywords-bulk-delete', [KeywordController::class, 'bulkDelete']);

    Route::get('keywords-analytics', [KeywordController::class, 'analytics']);

    Route::get('keywords-options-keyword-types', [KeywordController::class, 'keywordTypes']);
    Route::get('keywords-options-listings/{keywordType}', [KeywordController::class, 'listings']);

    Route::post('keywords-import-upload', [KeywordImportController::class, 'upload']);
    Route::get('keywords-import-headers/{uploadId}', [KeywordImportController::class, 'headers']);
    Route::post('keywords-import-map', [KeywordImportController::class, 'map']);
    Route::post('keywords-import-validate', [KeywordImportController::class, 'validateImport']);
    Route::post('keywords-import-confirm', [KeywordImportController::class, 'confirm']);
    Route::get('keywords-import-progress/{batchId}', [KeywordImportController::class, 'progress']);

    Route::get('keywords-export', [KeywordExportController::class, 'export']);
    Route::get('keywords-template', [KeywordExportController::class, 'template']);
});
Route::middleware(['throttle:60,1', 'validate.api.client'])->group(function () {
    Route::get('dynamic-posts/{dynamicPost}/template', [TemplateResolveController::class, 'showDynamicPostTemplate'])
        ->whereNumber('dynamicPost');
    Route::get('dynamic-posts/template/{slug}', [TemplateResolveController::class, 'showDynamicPostTemplateBySlug']);
    Route::get(
        'dynamic-post-form-steps/{postType}/builder',
        [DynamicPostFormStepController::class, 'builder']
    );

    Route::post(
        'dynamic-post-form-steps/{postType}/steps',
        [DynamicPostFormStepController::class, 'saveSteps']
    );

    Route::post(
        'dynamic-post-form-steps/{postType}/mapping',
        [DynamicPostFormStepController::class, 'saveMapping']
    );
});
Route::middleware(['validate.api.client', 'throttle:60,1'])->group(function () {
    Route::middleware(['throttle:60,1'])->get(
        'auth/getuser',
        [UserProfileController::class, 'show']
    );

    Route::middleware(['throttle:60,1'])->post(
        'auth/profile/personal',
        [UserProfileController::class, 'updatePersonal']
    );

    Route::middleware(['throttle:60,1'])->post(
        'auth/profile/address',
        [UserProfileController::class, 'updateAddress']
    );

    Route::middleware(['throttle:60,1'])->post(
        'auth/profile/documents',
        [UserProfileController::class, 'updateDocuments']
    );

    Route::middleware(['throttle:60,1'])->post(
        'auth/profile/photo',
        [UserProfileController::class, 'updatePhoto']
    );
    Route::get('frontend/listing-roles', [DynamicPostController::class, 'frontendListingRoleDropdown']);

    /*
    |--------------------------------------------------------------------------
    | Frontend User Listing Step Form
    |--------------------------------------------------------------------------
    */

    Route::get(
        'frontend/dynamic-post-step-form/{postType}',
        [DynamicPostFormStepController::class, 'frontendForm']
    );

    Route::post('frontend/listings', [DynamicPostController::class, 'storeFrontendListing']);

    Route::post('/check-user-duplicate', [Rolecontroller::class, 'checkUserDuplicate']);
    Route::post('/verify-register-otp', [AuthController::class, 'verifyRegisterOtp']);
    Route::get('users-property-listing', [UserPropertyListingController::class, 'index']);
    Route::get('user-listing-analytics', [UserPropertyListingController::class, 'analytics']);
    Route::prefix('frontend')->name('frontend.listings.')->group(function () {
        Route::get('/taxonomies', [FrontendListingController::class, 'taxonomies'])->name('taxonomies');
        Route::middleware('auth:sanctum')->post('/', [FrontendListingController::class, 'store'])->name('store');
    });
});
