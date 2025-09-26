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
use App\Http\Controllers\Amenity\Amenitycontroller;
use App\Http\Controllers\Property\Propertytypecontroller;
use App\Http\Controllers\Purpose\PurposeController;
use App\Http\Controllers\Property\Propertycontroller;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\Status\statuscontroller;
use App\Http\Controllers\Amenity\AmenitycategoriesController;
use App\Http\Controllers\Admin\Admincontroller;
use App\Http\Controllers\Rolecontroller;
use App\Http\Controllers\Permissioncontroller;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\Ticket\TicketController;
use App\Http\Controllers\Ticket\ticketstatuscontroller;
use App\Http\Controllers\Ticket\ticketprioritycontroller;
use App\Http\Controllers\Ticket\TicketTypeController;
use App\Http\Controllers\Ticket\TicketDepartmentController;
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
use App\Http\Controllers\OtpController;
use App\Http\Controllers\EmailOtpController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\CustomField\CustomFieldExportImportController;

use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\UserAssociationController;

use App\Http\Controllers\Auth\Kyc\KycController;




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



    Route::middleware(['validate.api.client'])->group(function () {

        Route::middleware(['throttle:1000,1'])->post('/register', [AuthController::class, 'register']);


        Route::middleware(['throttle:1000,1'])->post('/store-otp-verification-data', [UserController::class, 'storeOtpVerificationData']);

        Route::middleware(['throttle:1000,1'])->post('login', [AuthController::class, 'login']);
        Route::middleware(['throttle:1000,1'])->post('/logout', [AuthController::class, 'logout'])->middleware('api.token');

        Route::middleware(['throttle:1000,1'])->post('/check-unique', [UserController::class, 'checkUnique']);
        Route::middleware(['throttle:1000,1'])->post('/admin/profile/change-password', [UserController::class, 'changePassword'])->middleware('api.token');

        Route::middleware(['throttle:1000,1'])->post('forget-password', [ForgotPasswordController::class, 'forgetPassword']);
        Route::middleware(['throttle:1000,1'])->post('/reset-password-from', [ForgotPasswordController::class, 'resetPassword']);
        Route::middleware(['throttle:1000,1'])->get('/validate-reset-token', [ForgotPasswordController::class, 'validateResetToken']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('/user/search', [UserController::class, 'SearchUser']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('all-user-listing', [UserController::class, 'alluserlist']);


        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->get('get-details-byuserid', [UserController::class, 'getdetailsbyuserid']); // Done By softtonia
        Route::middleware(['allrole.token','throttle:1000,1'])->post('update-current-user-by-token', [UserController::class, 'updateCurrentUser']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('user-kyc-update', [KycController::class, 'updateKycStatus']);
        Route::middleware(['allrole.token','throttle:1000,1'])->post('user-complete-kyc', [KycController::class, 'completeKyc']);


        Route::middleware(['admin.token','throttle:1000,1'])->post('update-user-byuserid', [UserController::class, 'updateuserbyid']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('update-user-status', [UserController::class, 'updateuserstatus']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('create-user', [UserController::class, 'createUser']);
        Route::middleware(['allrole.token','throttle:1000,1'])->post('update-front-user-by-id', [UserController::class, 'updateUser']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('delete-user', [UserController::class, 'deleteUser']);
        Route::middleware(['throttle:1000,1'])->post('user-bulk-delete', [UserController::class, 'bulkDelete']);
        Route::middleware(['throttle:1000,1'])->get('/users/filter-by-role', [UserController::class, 'filterByRole']);
        Route::middleware(['throttle:1000,1'])->get('/users/filter-by-status', [UserController::class, 'filterByStatus']);
        Route::middleware(['throttle:1000,1'])->get('/get-user-status', [UserController::class, 'getUserStatusList']);

        Route::middleware(['throttle:1000,1'])->get('/get-all-users-by-role', [UserController::class, 'getDataUserDetailsByRole']);
        Route::middleware(['throttle:1000,1'])->get('/get-userdata-by-id', [UserController::class, 'getDataUserDetailsById']);



        Route::middleware(['OnlyCompany','throttle:1000,1'])->get('get-company-consultancy-listing', [CompanyConsultancyController::class, 'getConsultancyListingByCompany']);   // Done By softtonia
        Route::middleware(['OnlyCompany','throttle:1000,1'])->get('search-consultancy-by-id', [CompanyConsultancyController::class, 'searchConsultancyById']);  // Done By softtonia
        Route::middleware(['OnlyCompany','throttle:1000,1'])->post('send-request-by-company-to-consultancy', [CompanyConsultancyController::class, 'sendRequestByCompanyToConsultancy']); // Done By softtonia
        Route::middleware(['OnlyConsultancy','throttle:1000,1'])->get('get-all-consultancy-join-request-listing', [CompanyConsultancyController::class, 'getConsultancyAllJoinRequest']);  // Done By softtonia
        Route::middleware(['allowed_roles','throttle:1000,1'])->post('accept-decline-company-request-by-consultancy', [CompanyConsultancyController::class, 'acceptDeclineCompanyRequestByConsultancy']); // Done By softtonia
        Route::middleware(['OnlyConsultancy','throttle:1000,1'])->post('leave-the-comapny-by-consultancy', [CompanyConsultancyController::class, 'leaveTheComapnyByConsultancy']); // Done By softtonia
        Route::middleware(['OnlyConsultancy','throttle:1000,1'])->get('get-consultancy-details-with-company', [CompanyConsultancyController::class, 'getConsultancyDetailsWithCompany']);  // Done By softtonia

        Route::middleware(['OnlyCompany','throttle:1000,1'])->get('get-company-project-listing', [CompanyProjectController::class, 'getCompanyProjectListing']); // Done By softtonia
        Route::middleware(['OnlyCompany','throttle:1000,1'])->get('fetch-assigned-project-of-company', [CompanyProjectController::class, 'fetchAssignedProjectOfCompany']); // Done By softtonia
        Route::middleware(['throttle:1000,1'])->post('property-details-by-projectId', [UserController::class, 'propertyDetailsByProjectId']);
        Route::middleware(['OnlyConsultancy','throttle:1000,1'])->get('fetch-total-assigned-project-to-consultancy', [ConsultancyProjectController::class, 'fetchTotalAssignedProjectToConsultancy']);
        Route::middleware(['throttle:1000,1'])->get('fetch-consultancy-total-assigned-project', [ConsultancyProjectController::class, 'fetchConsultancyTotalAssignedProjects']);
        Route::middleware(['throttle:1000,1'])->post('assign-project-to-agent-by-consultancy', [ConsultancyProjectController::class, 'assignProjectToAgentByConsultancy']);
        Route::middleware(['throttle:1000,1'])->get('fetch-assigned-project-of-agent', [AgentProjectController::class, 'fetchAssignedProjectOfAgent']);
        Route::middleware(['throttle:1000,1'])->get('fetch-agent-total-assigned-project', [AgentProjectController::class, 'fetchAgentTotalAssignedProject']);
        Route::middleware(['throttle:1000,1'])->get('fetch-total-project-of-consultancy', [ConsultancyProjectController::class, 'fetchTotalProjectOfConsultancy']);
        Route::middleware(['throttle:1000,1'])->post('view-project-details-of-consultancy', [ConsultancyProjectController::class, 'viewProjectDetailsOfConsultancy']);
        Route::middleware(['throttle:1000,1'])->post('view-project-details-of-company', [CompanyProjectController::class, 'viewProjectDetailsOfCompany']);
        Route::middleware(['throttle:1000,1'])->post('globle-search-engine', [SearchEngineController::class, 'globleSearchEngine']);


        Route::prefix('search')->group(function () {
            // GET filter data by search result
            Route::middleware(['throttle:1000,1'])->get('/get-filterdata-by-search-result', [SearchEngineController::class, 'getFilterDataBySearchResult']);

            // POST apply filters and get property list
            Route::middleware(['throttle:1000,1'])->post('/apply-filters', [SearchEngineController::class, 'applyFilters']);
        });


        ############# new ########
            // 1. Global Search API
                Route::middleware(['throttle:1000,1'])->post('/global-search', [SearchEngineController::class, 'globalSearch']);

                // 2. Global Filters API
                Route::middleware(['throttle:1000,1'])->post('/global-filters', [SearchEngineController::class, 'globalFilters']);

                // 3. Apply Filter API
                Route::middleware(['throttle:1000,1'])->post('/apply-filter', [SearchEngineController::class, 'applyFilter']);
        ############ end new #########

        Route::middleware(['throttle:1000,1'])->get('listing-of-all-projects', [UserController::class, 'listingOfAllProjects']);

        Route::middleware(['throttle:1000,1'])->get('all-top-agent-listing', [UserController::class, 'allTopAgentListing']);

        Route::middleware(['throttle:1000,1'])->get('listing-of-trending-project', [UserController::class, 'listingOfAllTrendingProject']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('update-site-setting', [SiteSettingController::class, 'updateSiteSetting']);
        Route::middleware(['throttle:1000,1'])->get('site-setting', [SiteSettingController::class, 'siteSetting']);
        Route::middleware(['throttle:1000,1'])->get('listing-of-property-with-project', [UserController::class, 'listingOfPropertyWithProject']);


        Route::middleware(['allow.owner.role','throttle:1000,1'])->get('owner-dashboard-analytics', [OwnerDashboardAnalyticsController::class, 'ownerDashboardAnalytics']);




        Route::middleware(['throttle:1000,1'])->get('property-listing-by-location', [UserController::class, 'propertyListingByLocation']);




        Route::middleware(['admin.token','throttle:1000,1'])->get('get-all-consultancy-listing', [UserController::class, 'allConsultancyListing']); //Done By softtonia
        Route::middleware(['admin_or_consultancy','throttle:1000,1'])->get('get-consultancy-agents/{id}', [UserController::class, 'getConsultancyAgents']);
        Route::middleware(['company.admin','throttle:1000,1'])->get('get-all-consultancy-listing-by-company', [UserController::class, 'getAllConsultancyListingByCompany']); //Done By softtonia


        // User route will end from here
        Route::middleware(['throttle:1000,1'])->get('get-all-roles', [RoleController::class, 'getallrole']);
        Route::middleware(['throttle:1000,1'])->get('get-default-roles', [RoleController::class, 'getDefaultRole']);
        Route::middleware(['throttle:1000,1'])->post('/password/reset', [ResetPasswordController::class, 'reset'])->name('password.update');
        Route::middleware(['throttle:1000,1'])->get('verify-email/{id}/{code}', [VerificationController::class, 'verifyEmail'])->name('verify-email');



        // ========== Subscribe Emails Import ===============
        Route::middleware(['throttle:1000,1'])->post('insert-subscribe-email', [SubscribeController::class, 'insertSubscribeEmail']);
        Route::middleware(['throttle:1000,1'])->get('listing-subscribe-email', [SubscribeController::class, 'listingOfSubscribedEmails'])->middleware('admin.token');
        Route::middleware(['throttle:1000,1'])->post('import-subscribed-emails', [SubscribeController::class, 'importSubscribedEmails'])->middleware('admin.token');

        // ========= Subscribe Emails Export ===============
        Route::middleware(['throttle:1000,1'])->get('/subscribed-emails/export/{format}', [SubscribeController::class, 'exportSubscribedEmails'])->name('subscribed_emails.export')->middleware('admin.token');

        // =======Error Log Listing=================
        Route::middleware(['throttle:1000,1'])->get('error-logs', [ErrorLogController::class, 'listErrorLogs'])->middleware('api.token');
        Route::middleware(['throttle:1000,1'])->get('error-logs/download/{file}', [ErrorLogController::class, 'downloadFile'])->middleware('api.token');
        // Single delete route
        Route::middleware(['throttle:1000,1'])->delete('/error-logs/delete/{fileName}', [ErrorLogController::class, 'deleteErrorLog'])->middleware('api.token');
        // Bulk delete route
        Route::middleware(['throttle:1000,1'])->post('/error-logs/bulk-delete', [ErrorLogController::class, 'bulkDeleteErrorLogs'])->middleware('api.token');


        // ======Project Listing============
        Route::middleware(['allow.admin_company','throttle:1000,1'])->post('add-project-listing', [ProjectlistingController::class, 'store']);
        Route::middleware(['allow.admin_company','throttle:1000,1'])->post('edit-project-listing', [ProjectlistingController::class, 'update']);
        Route::middleware(['allow.admin_company','throttle:1000,1'])->post('delete-project-listing', [ProjectlistingController::class, 'destroy']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('get-all-project-listing-by-admin', [ProjectlistingController::class, 'indexByAdmin']);
        Route::middleware(['allow.admin_company','throttle:1000,1'])->get('/user-project', [ProjectlistingController::class, 'getUserProject']);

        Route::middleware(['allow.admin_company','throttle:1000,1'])->get('get-data-project/{id}', [ProjectlistingController::class, 'getdatabyId']);
        Route::middleware(['allow.admin_company','throttle:1000,1'])->post('update-project-status', [ProjectlistingController::class, 'updateProjectStatus']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('update-project-status-by-admin', [ProjectlistingController::class, 'updateProjectStatusByAdmin']);
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('get-project-by-userid', [ProjectlistingController::class, 'getProjectByUserId']);
        Route::middleware(['allow.admin_company','throttle:1000,1'])->post('project-bulk-delete', [ProjectlistingController::class, 'bulkDelete']);

        Route::middleware(['allow.admin_company','throttle:1000,1'])->post('update-project-temporary-status', [ProjectlistingController::class, 'updateTemporaryStatus']);
        Route::middleware(['allow.admin_company','throttle:1000,1'])->get('project-search', [ProjectlistingController::class, 'projectSearch']);
        ### No Auth ###


        Route::middleware(['throttle:1000,1'])->get('get-project-by-user-id-filter-by-purpose/{userId}',[ProjectlistingController::class,'getProjectsByUserId']);
         Route::middleware(['throttle:1000,1'])->get('get-related-projects-id/{projectId}',[ProjectlistingController::class,'getRelatedProjectsByProjectId']);

        // ======Developer Listing============
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->post('add-developer-listing', [DeveloperlistingController::class, 'store']);
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->post('edit-developer-listing', [DeveloperlistingController::class, 'update']);
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->post('developer-delete', [DeveloperlistingController::class, 'destroy']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('fetch-all-developer-listing-by-admin', [DeveloperlistingController::class, 'indexByAdmin']);
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->get('get-data-developer/{id}', [DeveloperlistingController::class, 'getdatabyId']);
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->post('developer-bulk-delete', [DeveloperlistingController::class, 'bulkDelete']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('update-developer-status', [DeveloperlistingController::class, 'updateDeveloperStatus']);
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->get('/user-developer', [DeveloperlistingController::class, 'getUserDeveloper']);
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->post('get-all-developer-by-location-id', [DeveloperlistingController::class, 'getAllDeveloperByLocationId']);

        Route::middleware(['allow.admin_developer','throttle:1000,1'])->post('update-developer-temporary-status', [DeveloperlistingController::class, 'updateTemporaryStatus']);
        Route::middleware(['allow.admin_developer','throttle:1000,1'])->get('/developer-search', [DeveloperlistingController::class, 'developerSearch']);

        ### No Auth ###


         Route::middleware(['throttle:1000,1'])->get('get-developer-by-user-id-filter-by-purpose/{userId}',[DeveloperlistingController::class,'getDevelopersByUserId']);
         Route::middleware(['throttle:1000,1'])->get('get-related-developers-id/{developerId}',[DeveloperlistingController::class,'getRelatedDevelopersByDeveloperId']);




        // =======Property Listing============
        Route::middleware(['allow.property.listing','throttle:1000,1'])->post('add-properties-listing', [PropertylistingController::class, 'store']);
        Route::middleware(['allow.property.listing','throttle:1000,1'])->post('edit-properties-listing', [PropertylistingController::class, 'update']);
        Route::middleware(['allow.property.listing','throttle:1000,1'])->post('delete-properties-listing', [PropertylistingController::class, 'destroy']);
        Route::middleware(['allow.property.listing','throttle:1000,1'])->get('get-all-properties-listing', [PropertylistingController::class, 'indexByadmin']);

        Route::middleware(['api.token','throttle:1000,1'])->get('properties-search', [PropertylistingController::class, 'propertiesSearch']);

        Route::middleware(['allow.property.listing','throttle:1000,1'])->get('get-data-properties/{id}', [PropertylistingController::class, 'getdatabyId']);
        Route::middleware(['allow.property.listing','throttle:1000,1'])->post('update-temporary-status', [PropertylistingController::class, 'updateTemporaryStatus']);
        Route::middleware(['api.token','throttle:1000,1'])->get('get-temporary-statuses', [PropertylistingController::class, 'getTemporaryStatuses']);
        Route::middleware(['api.token','throttle:1000,1'])->get('get-property-statuses', [PropertylistingController::class, 'getPropertyStatuses']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('update-property-status', [PropertylistingController::class, 'updatePropertyStatus']);
        Route::middleware(['api.token','throttle:1000,1'])->post('get-all-project-by-location-id', [PropertylistingController::class, 'getAllProjectByLocationId']);
        Route::middleware(['allow.admin_company','throttle:1000,1'])->post('get-company-project-by-location-id', [PropertylistingController::class, 'getComapnyProjectByLocationId']);
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('properties-bulk-delete', [PropertylistingController::class, 'bulkDelete']);
        //
        #### No Auth ######

        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->get('/user-properties', [PropertylistingController::class, 'getUserProperties']);

        Route::middleware(['throttle:1000,1'])->get('/get-property-by-user-id-filter-by-purpose/{userId}', [PropertylistingController::class, 'getPropertyByUserId']);
        Route::middleware(['throttle:1000,1'])->get('/get-related-properties-id/{propertyId}', [PropertylistingController::class, 'getRelatedPropertiesByPropertyId']);


        // Start Website Route

            // Locations
                Route::middleware(['throttle:1000,1'])->get('/locations', [LocationController::class, 'getCityGroups']);
                Route::middleware(['throttle:1000,1'])->get('/get-localities-filter-by-location-id', [LocationController::class, 'getAreaLocalities']);
            // Project Listing No Auth
                Route::middleware(['throttle:1000,1'])->get('get-all-project-listing-no-auth', [ProjectlistingController::class, 'index']);
                Route::middleware(['throttle:1000,1'])->get('get-data-project-no-auth/{id}', [ProjectlistingController::class, 'getdatabyIdNoAuth']);

            // Developer Listing No Auth
                Route::middleware(['throttle:1000,1'])->get('fetch-all-developer-listing-no-auth', [DeveloperlistingController::class, 'index']);
                Route::middleware(['throttle:1000,1'])->get('get-data-developer-no-auth/{id}', [DeveloperlistingController::class, 'getdatabyIdNoAuth']);

            // Property Listing No Auth
                Route::middleware(['throttle:1000,1'])->get('get-all-properties-listing-no-auth', [PropertylistingController::class, 'index']);
                Route::middleware(['throttle:1000,1'])->get('get-data-properties-no-auth/{id}', [PropertylistingController::class, 'getdatabyIdNoAuth']);

        // End Website Route


        // frontend site
        // =======Front Property Listing============


        Route::middleware(['throttle:1000,1'])->post('store-property-analytics', [frontPropertylistingController::class, 'storePropertyAnalytics']);
        Route::middleware(['throttle:1000,1'])->get('list-property-analytics', [frontPropertylistingController::class, 'listPropertyAnalytics']);
        Route::middleware(['throttle:1000,1'])->get('view-property-analytics', [frontPropertylistingController::class, 'viewPropertyAnalytics']);
        // frontend side
        // =======Front Project Listing============
        Route::middleware(['throttle:1000,1'])->post('add-website-project-listing', [frontProjectlistingController::class, 'store']);
        Route::middleware(['throttle:1000,1'])->post('edit-website-project-listing', [frontProjectlistingController::class, 'update']);
        Route::middleware(['throttle:1000,1'])->post('delete-website-project-listing', [frontProjectlistingController::class, 'destroy']);
        Route::middleware(['throttle:1000,1'])->get('get-all-website-project-listing', [frontProjectlistingController::class, 'index']);
        Route::middleware(['throttle:1000,1'])->get('get-data-website-project/{id}', [frontProjectlistingController::class, 'getdatabyId']);
        Route::middleware(['throttle:1000,1'])->post('update-website-project-status', [frontProjectlistingController::class, 'updateProjectStatus']);
        Route::middleware(['throttle:1000,1'])->post('store-project-analytics', [frontProjectlistingController::class, 'storeProjectAnalytics']);
        Route::middleware(['throttle:1000,1'])->get('list-project-analytics', [frontProjectlistingController::class, 'listProjectAnalytics']);
        Route::middleware(['throttle:1000,1'])->get('view-project-analytics', [frontProjectlistingController::class, 'viewProjectAnalytics']);
    });
    Route::middleware(['throttle:1000,1'])->post('admin/login', [AdminController::class, 'login'])->name('login');

    // admin route will start from here

    Route::middleware(['admin.token','throttle:1000,1'])->post('/profile/update', [AdminController::class, 'update']);
    // Add other routes here if needed


    // Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // dd(1);

    Route::middleware(['validate.api.client'])->group(function () {

        Route::middleware(['admin'])->prefix('admin')->group(function () {
            Route::middleware(['token.expiration','throttle:1000,1'])->group(function () {

                Route::middleware('admin.token')->get('/get-admin-profile', [Admincontroller::class, 'getAdminProfile']);
            });

            Route::middleware(['admin.token','throttle:1000,1'])->post('/login-restricted', [Admincontroller::class, 'LoginActiveInactive']);
            Route::middleware(['throttle:1000,1'])->post('/user-bulk-delete', [Admincontroller::class, 'userAllRecordBulksDelete']);

            Route::middleware(['admin.token','throttle:1000,1'])->post('/mail-config', [MailConfigController::class, 'store']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/mail-config/{id}', [MailConfigController::class, 'update']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/get-mail-config', [MailConfigController::class, 'getMailConfig']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/active-mail-config', [MailConfigController::class, 'ActiveMailConfig']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/mail-config-delete/{id}', [MailConfigController::class, 'deleteMailConfig']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/bulk-mail-configs-delete', [MailConfigController::class, 'bulkDeleteMailConfigs']);
            Route::middleware(['admin.token','throttle:1000,1'])->get('/search-mail-configs', [MailConfigController::class, 'searchMailConfigs']);


            Route::middleware(['throttle:1000,1'])->post('/create-role-prefix-repeater', [SystemController::class, 'CreateRolePrefixRepeater']);
            Route::middleware(['throttle:1000,1'])->post('/get-role-prefix-repeater', [SystemController::class, 'GetRolePrefixRepeater']);
            Route::middleware(['throttle:1000,1'])->post('/delete-role-prefix-repeater/{ic}', [SystemController::class, 'DeleteRolePrefixRepeater']);
            Route::middleware(['throttle:1000,1'])->post('/update-role-prefix-repeater-by-id/{id}', [SystemController::class, 'UpdateRolePrefixRepeater']);

            Route::middleware(['throttle:1000,1'])->post('role-create', [RoleController::class, 'createRole']);
            Route::middleware(['throttle:1000,1'])->post('role-edit', [RoleController::class, 'editRole']);
            Route::middleware(['throttle:1000,1'])->post('role-delete', [RoleController::class, 'deleteRole']);
            Route::middleware(['throttle:1000,1'])->get('role-listing/{id?}', [RoleController::class, 'index']); // Optional ID parameter
            Route::middleware(['throttle:1000,1'])->post('roles/bulk-delete', [RoleController::class, 'bulkDeleteRoles']);
            Route::middleware(['throttle:1000,1'])->post('roles/search', [RoleController::class, 'searchRole']);
        });

        Route::middleware(['admin.token','throttle:1000,1'])->post('import-keywords', [Admincontroller::class, 'import']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('export-keywords', [Admincontroller::class, 'export']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('fetch-keywords', [Admincontroller::class, 'fetchKeywordList']);


        Route::middleware(['api.token','throttle:1000,1'])->get('get-keyword-by-keyword-type', [Admincontroller::class, 'getKeywordbykeywordtype']);
        // ======= Analytics =========
        Route::middleware(['admin.token','throttle:1000,1'])->get('admin-dashboard-analytics', [AdminDashboardAnalyticsController::class, 'adminDashboardAnalytics']);
        Route::middleware(['api.token','throttle:1000,1'])->get('business-dashboard-analytics', [BusinessDashboardAnalyticsController::class, 'businessDashboardAnalytics']);
        Route::middleware(['allow.owner.role','throttle:1000,1'])->get('owner-dashboard-analytics', [OwnerDashboardAnalyticsController::class, 'ownerDashboardAnalytics']);

        // =======Location============

        Route::middleware(['throttle:1000,1'])->get('/all-location-list', [LocationController::class, 'locationList']);




        // ======= Bulk Upload Country , State, City in CSV Format ===========

        Route::middleware(['admin.token','throttle:1000,1'])->post('bulk-upload-location-csv', [Locationcontroller::class, 'bulkUploadCSC']);

        // =======Amenity============


        Route::middleware(['admin.token','throttle:1000,1'])->post('amenity-create', [Amenitycontroller::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('amenity-update', [Amenitycontroller::class, 'update']);
        Route::middleware(['api.token','throttle:1000,1'])->get('amenity-listing', [Amenitycontroller::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('amenity', [Amenitycontroller::class, 'destroy']);
        Route::middleware(['api.token','throttle:1000,1'])->post('getdatabyId-amenity', [Amenitycontroller::class, 'getdatabyId']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('amenity-bulk-delete', [Amenitycontroller::class, 'bulkDelete']);


        // =======Property Type============


        Route::middleware(['admin.token','throttle:1000,1'])->post('property-type-create', [Propertytypecontroller::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('property-type-update', [Propertytypecontroller::class, 'update']);
        Route::middleware(['throttle:1000,1'])->get('property-type-listing', [Propertytypecontroller::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('property-type-delete', [Propertytypecontroller::class, 'destroy']);
        Route::middleware(['api.token','throttle:1000,1'])->post('getdatabyId-property-type', [Propertytypecontroller::class, 'getdatabyId']);


        Route::middleware(['admin.token','throttle:1000,1'])->post('property-type-bulk-delete', [Propertytypecontroller::class, 'bulkDelete']);
        Route::middleware(['api.token','throttle:1000,1'])->get('property-type-search', [Propertytypecontroller::class, 'searchByName'])->name('propertytype.search');


        // =======Status============
        Route::middleware(['admin.token','throttle:1000,1'])->post('status-create', [statuscontroller::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('status-update', [statuscontroller::class, 'update']);
        Route::middleware(['throttle:1000,1'])->get('status-listing', [statuscontroller::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('status', [statuscontroller::class, 'destroy']);
        Route::middleware(['api.token','throttle:1000,1'])->get('getdatabyId-status', [statuscontroller::class, 'getdatabyId']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('status-bulk-delete', [statuscontroller::class, 'bulkDelete']);
        Route::middleware(['api.token','throttle:1000,1'])->get('status-search', [statuscontroller::class, 'searchByName'])->name('status.search');

        // =======Purpose============

        Route::middleware(['validate.api.client'])->group(function () {

            Route::middleware(['admin.token','throttle:1000,1'])->post('purpose-create', [PurposeController::class, 'store']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('purpose-update', [PurposeController::class, 'update']);
            Route::middleware(['throttle:1000,1'])->get('purpose-listing', [PurposeController::class, 'index']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('purpose-delete', [PurposeController::class, 'destroy']);
            Route::middleware(['api.token','throttle:1000,1'])->post('getdatabyId-purpose', [PurposeController::class, 'getdatabyId']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('purpose-bulk-delete', [PurposeController::class, 'bulkDelete']);
            Route::middleware(['api.token','throttle:1000,1'])->get('purpose-search', [PurposeController::class, 'searchByName'])->name('purposes.search');

        });


        // =======Property============
        Route::middleware(['admin.token','throttle:1000,1'])->post('property-create', [Propertycontroller::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('property-update', [Propertycontroller::class, 'update']);
        Route::middleware(['throttle:1000,1'])->get('property-listing', [Propertycontroller::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('property-delete', [Propertycontroller::class, 'destroy']);
        Route::middleware(['api.token','throttle:1000,1'])->get('properties/{id}', [PropertyController::class, 'getPropertyAndType']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('property-bulk-delete', [PropertyController::class, 'bulkDelete']);
        Route::middleware(['api.token','throttle:1000,1'])->get('property-search', [PropertyController::class, 'searchByName'])->name('property.search');



        // =======Amenity Categories============

        Route::middleware(['admin.token','throttle:1000,1'])->post('add-amenities-categories', [AmenitycategoriesController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('edit-amenities-categories', [AmenitycategoriesController::class, 'update']);
        Route::middleware(['api.token','throttle:1000,1'])->get('list-amenities-categories', [AmenitycategoriesController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('delete-amenities-categories', [AmenitycategoriesController::class, 'destroy']);
        Route::middleware(['api.token','throttle:1000,1'])->post('getdatabyId-amenitycategories', [AmenitycategoriesController::class, 'getdatabyId']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('amenities-categories-bulk-delete', [AmenitycategoriesController::class, 'bulkDelete']);

        Route::middleware(['api.token','throttle:1000,1'])->get('search-amenities-categories', [AmenitycategoriesController::class, 'searchByName']);

        // admin route will end from here


        // custom field will start from here
        Route::middleware(['throttle:1000,1'])->get('custom-field-listing-by-type', [CustomFieldController::class, 'customFieldListingByType']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('add-custom-fields', [CustomFieldController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('edit-custom-fields-by-group-id', [CustomFieldController::class, 'updateCustomFieldByGroupId']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('delete-custom-fields', [CustomFieldController::class, 'delete']);
        Route::middleware(['throttle:1000,1'])->post('get-custom-fields-by-group-id', [CustomFieldController::class, 'getCustomFieldByGroupId']);
        Route::middleware(['allrole.token','throttle:1000,1'])->get('model-listing', [CustomFieldController::class, 'modelListing']);
        Route::middleware(['throttle:1000,1'])->get('all_template_id_listings', [CustomFieldController::class, 'customFieldUniqueCode']);
        Route::middleware(['allrole.token','throttle:1000,1'])->get('condition-listing', [CustomFieldController::class, 'conditionListing']);
        Route::middleware(['allrole.token','throttle:1000,1'])->get('custom-field-listing', [CustomFieldController::class, 'customFieldListing']);
        Route::middleware(['throttle:1000,1'])->get('property-type-listing-by-propertyid', [CustomFieldController::class, 'propertyTypeListingByPropertyId']);
        Route::middleware(['throttle:1000,1'])->get('property-status-listing-by-propertytype', [CustomFieldController::class, 'propertyStatusListingByPropertyType']);
        Route::middleware(['throttle:1000,1'])->get('get-amenities-data', [CustomFieldController::class, 'GetAmenitiesData']);
        Route::middleware(['throttle:1000,1'])->post('get-custom-filded-list', [CustomFieldController::class, 'GetCustomFields']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('update-custom-field/{id}', [CustomFieldController::class, 'updateCustomField']);
        Route::middleware(['throttle:1000,1'])->post('custom-fields/search-and-filter', [CustomFieldController::class, 'searchAndFilter']);
        Route::middleware(['throttle:1000,1'])->post('custom-fields/delete-custom-field', [CustomFieldController::class, 'deleteCustomField']);
        Route::middleware(['throttle:1000,1'])->post('slug-uniqueness-check', [CustomFieldController::class, 'slugUniquesCheck']);
        Route::middleware(['throttle:1000,1'])->get('get-model-condition-record', [CustomFieldController::class, 'getAllModelConditionRecords']);
        Route::middleware(['allrole.token','throttle:1000,1'])->get('get-custom-field-model-multi-condition', [CustomFieldController::class, 'getCustomFieldModelMultiCondition']);
        Route::middleware(['throttle:1000,1'])->post('custom-field-listing-by-model-conditionid', [CustomFieldController::class, 'customFieldListingByModelConditionId']);

        Route::middleware(['throttle:1000,1'])->get('get-custom-field-by-id/{id}', [CustomFieldController::class, 'getCustomFieldById']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('edit-custom-fields-by-id/{id}', [CustomFieldController::class, 'updateCustomFieldById']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('delete-custom-fields-by-id', [CustomFieldController::class, 'deleteCustomFieldById']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('bulk-delete-custom-fields-by-id', [CustomFieldController::class, 'bulkDeleteCustomFieldByIds']);

        // custom field exaport / import

        Route::middleware(['admin.token','throttle:1000,1'])->get('/export-custom-fields-csv', [CustomFieldExportImportController::class, 'exportToCsv']);
         Route::middleware(['admin.token','throttle:1000,1'])->post('/import-custom-fields-csv', [CustomFieldExportImportController::class, 'importFromCsv']);


        // custom field will end from here

        // Group Route will start from here
        Route::middleware(['admin.token','throttle:1000,1'])->post('groups-create', [GroupController::class, 'createGroup']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('groups-update/{id}', [GroupController::class, 'updateGroup']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('groups-list', [GroupController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('groups-delete/{id}', [GroupController::class, 'deleteGroup']);
        Route::middleware(['throttle:1000,1'])->get('/check-unique-group-name', [GroupController::class, 'checkUniqueGroupName']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('groups-bulk-delete', [GroupController::class, 'bulkDeleteGroups']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('groups-search', [GroupController::class, 'searchByGroupName']);



        // Group Route will end from here

        // Permission Route will start from here
        Route::middleware(['throttle:1000,1'])->post('permissions-delete', [PermissionController::class, 'deletePermission']);
        Route::middleware(['throttle:1000,1'])->get('permissions-listing', [PermissionController::class, 'index']);
        Route::middleware(['throttle:1000,1'])->post('permissions/assign', [PermissionController::class, 'assignPermission']);
        Route::middleware(['throttle:1000,1'])->post('role/assign', [Rolecontroller::class, 'assignRole']);
        Route::middleware(['throttle:1000,1'])->post('remove/permission', [PermissionController::class, 'removePermission']);
        Route::middleware(['throttle:1000,1'])->get('/role/{roleId}/permissions', [PermissionController::class, 'getPermissionsByRole']);

        Route::middleware(['throttle:1000,1'])->post('assign-permissions', [PermissionController::class, 'assignDynamicPermissions']);
        Route::middleware(['throttle:1000,1'])->get('/permissions/{role_id}', [PermissionController::class, 'getPermissionsByRole']);
        Route::middleware(['throttle:1000,1'])->get('/model-names', [PermissionController::class, 'getModelNames']);
        // Permission Route will end from here



        // Ticket Route will start from here
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('tickets-create', [TicketController::class, 'store']);
        Route::middleware(['allrole.token','throttle:1000,1'])->get('tickets-list', [TicketController::class, 'index']);
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('tickets-update', [TicketController::class, 'update']);
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('tickets-delete', [TicketController::class, 'destroy']);
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('tickets-bulk-delete', [TicketController::class, 'bulkDestroy']);
        Route::middleware(['allrole.token','throttle:1000,1'])->post('get-tickets-by-id', [TicketController::class, 'show']);
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('tickets-search', [TicketController::class, 'searchByTicketNumber']);


        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('get-tickets-by-token', [TicketController::class, 'getTicketByToken']);

        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->post('update-tickets-status', [TicketController::class, 'updateTicketStatus']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-status-create', [ticketstatuscontroller::class, 'store']);  //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-status-update', [ticketstatuscontroller::class, 'update']); //Done By softtonia
        Route::middleware(['throttle:1000,1'])->get('tickets-status-list', [ticketstatuscontroller::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-status-delete', [ticketstatuscontroller::class, 'destroy']); //Done By softtonia

        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-status-bulk-delete', [ticketstatuscontroller::class, 'bulkDelete']);
        Route::middleware(['throttle:1000,1'])->get('search-tickets-status-name', [ticketstatuscontroller::class, 'searchTicketStatusName']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('get-tickets-status-byid', [ticketstatuscontroller::class, 'show']); //Done By softtonia

        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-department-create', [TicketDepartmentController::class, 'store']);  //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-department-update', [TicketDepartmentController::class, 'update']); //Done By softtonia
        Route::middleware(['throttle:1000,1'])->get('tickets-department-list', [TicketDepartmentController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-department-delete', [TicketDepartmentController::class, 'destroy']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('get-tickets-department-byid', [TicketDepartmentController::class, 'show']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-department-bulk-delete', [TicketDepartmentController::class, 'bulkDestroy']);


        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-priority-create', [ticketprioritycontroller::class, 'store']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-priority-update', [ticketprioritycontroller::class, 'update']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->get('tickets-priority-list', [ticketprioritycontroller::class, 'index']); //Done By softtonia
        Route::middleware(['throttle:1000,1'])->post('tickets-priority-delete', [ticketprioritycontroller::class, 'destroy']); //Done By softtonia
        Route::middleware(['throttle:1000,1'])->post('tickets-priority-bulk-delete', [ticketprioritycontroller::class, 'bulkDelete']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('get-tickets-priority-byid', [ticketprioritycontroller::class, 'show']); //Done By softtonia

        Route::middleware(['admin.token','throttle:1000,1'])->get('search-tickets-priority', [ticketprioritycontroller::class, 'searchTicketPriority']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-type-create', [TicketTypeController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-type-update', [TicketTypeController::class, 'update']);
        Route::middleware(['throttle:1000,1'])->get('tickets-type-list', [TicketTypeController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('tickets-type-delete', [TicketTypeController::class, 'destroy']);
        Route::middleware(['throttle:1000,1'])->post('get-tickets-type-byid', [TicketTypeController::class, 'show']);
        Route::middleware(['admin.token','throttle:1000,1'])->delete('/tickets-type-bulk-delete', [TicketTypeController::class, 'bulkDelete']);
        Route::middleware(['throttle:1000,1'])->get('search-tickets-type', [TicketTypeController::class, 'searchTicketType']);


        Route::middleware(['allrole.token','throttle:1000,1'])->post('tickets/respond', [TicketController::class, 'respond']);
        Route::middleware(['throttle:1000,1'])->get('tickets-respond-list', [TicketController::class, 'respondlist']);
        // ticket response history
        Route::middleware(['adminOrCurrentUser','throttle:1000,1'])->get('/tickets-response-list-history/{ticketId}', [TicketController::class, 'ticketResponseHistory']);

        // Ticket Route will end from here

        // Agent Route will start from here
        Route::middleware(['throttle:1000,1'])->post('agent-store', [AgentController::class, 'store']);
        Route::middleware(['throttle:1000,1'])->post('agent-update', [AgentController::class, 'update']);
        Route::middleware(['throttle:1000,1'])->post('agent', [AgentController::class, 'destroy']);
        Route::middleware(['throttle:1000,1'])->post('agents/toggle-status', [AgentController::class, 'toggleStatus']);
        Route::middleware(['consultancy.role','throttle:1000,1'])->post('send-request-by-consultancy-to-agent', [AgentController::class, 'sendRequestByConsultancyToAgent']);
        Route::middleware(['throttle:1000,1'])->post('accept-decline-request-by-consultancy-to-agent', [AgentController::class, 'AcceptDeclineRequestByConsultancyToAgent']);
        Route::middleware(['throttle:1000,1'])->post('leave-the-consultancy', [AgentController::class, 'leaveTheConsultancy']);
        Route::middleware(['throttle:1000,1'])->post('get-agent-details', [AgentController::class, 'getAgentDetails']);
        Route::middleware(['throttle:1000,1'])->get('get-all-join-request-listing', [AgentController::class, 'getAllJoinRequestList']);
        Route::middleware(['throttle:1000,1'])->get('get-consultancy-details', [AgentController::class, 'getConsultancyDetails']);
        Route::middleware(['throttle:1000,1'])->post('create-agent', [UserController::class, 'createAgent']);
        Route::middleware(['throttle:1000,1'])->get('get-consultancy-agent-listing', [AgentController::class, 'getConsultancyAgentListing']);
        Route::middleware(['throttle:1000,1'])->post('search-agent-by-id', [AgentController::class, 'searchAgentByID']);

        // consultancy to company routes
        Route::middleware(['throttle:1000,1'])->post('assign-project-to-consultancy-by-company', [UserController::class, 'assignProjectToConsultancyByCompany']);


        // Agent Route will end from here

        // Media Route will start from here
        Route::middleware(['admin.token','throttle:1000,1'])->post('media/add', [MediaController::class, 'addMedia']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('media/update', [MediaController::class, 'updateMedia']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('media', [MediaController::class, 'deleteMedia']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('media-list', [MediaController::class, 'index']);
        // Media Route will end from here


        // =========Page=======

        // =========16-07-2025=======

        Route::middleware(['admin.token','throttle:1000,1'])->get('get-all-pages-list', [PageController::class, 'index']);
        Route::middleware(['throttle:1000,1'])->get('get-pages-by-id', [PageController::class, 'show']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('create-pages', [PageController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('update-pages-by-id/{id}', [PageController::class, 'update']);
        Route::middleware(['admin.token','throttle:1000,1'])->delete('delete-pages-by-id/{id}', [PageController::class, 'destroy']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('bulk-delete-pages', [PageController::class, 'bulkDestroy']);
        Route::middleware(['admin.token','throttle:1000,1'])->get('search-pages', [PageController::class, 'searchPage']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('check-unique-pages', [PageController::class, 'checkUnique']);





        // =========About us========

        Route::middleware(['throttle:1000,1'])->get('/about-us', [AboutUsController::class, 'show']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('/about-us', [AboutUsController::class, 'storeOrUpdate']);


        // =========Property Valuation========
        Route::middleware(['admin.token','throttle:1000,1'])->post('property-valuation-update', [PropertyValuationController::class, 'update']);
        Route::middleware(['throttle:1000,1'])->get('property-valuation-list', [PropertyValuationController::class, 'index']);


        // =========Help Cat========
        Route::middleware(['throttle:1000,1'])->get('help-category-list', [HelpCategoryController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-category-create', [HelpCategoryController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-category-update', [HelpCategoryController::class, 'update']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-category-delete', [HelpCategoryController::class, 'delete']);
        Route::middleware(['throttle:1000,1'])->get('get-help-category-by-id/{id}', [HelpCategoryController::class, 'getdatabyId']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-category-bulk-delete', [HelpCategoryController::class, 'bulkDelete']);


        // ==========Help Subcat=======
        Route::middleware(['throttle:1000,1'])->get('help-subcategory-list', [HelpSubcategoryController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-subcategory-create', [HelpSubcategoryController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-subcategory-update', [HelpSubcategoryController::class, 'update']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-subcategory-delete', [HelpSubcategoryController::class, 'delete']);
        Route::middleware(['throttle:1000,1'])->get('get-help-subcategory-by-id/{id}', [HelpSubcategoryController::class, 'getdatabyId']);
        Route::middleware(['throttle:1000,1'])->post('help-subcategory-by-categoryid', [HelpSubcategoryController::class, 'getHelpSubcategoryByCategoryId']);

        Route::middleware(['admin.token','throttle:1000,1'])->post('help-subcategory-bulk-delete', [HelpSubcategoryController::class, 'bulkDelete']);

        // ===========Help Childcat=======
        Route::middleware(['throttle:1000,1'])->get('help-childcategory-list', [HelpChildcategoryController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-childcategory-create', [HelpChildcategoryController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-childcategory-update', [HelpChildcategoryController::class, 'update']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-childcategory-delete', [HelpChildcategoryController::class, 'delete']);
        Route::middleware(['throttle:1000,1'])->get('get-help-childcategory-by-id/{id}', [HelpChildcategoryController::class, 'getdatabyId']);
        Route::middleware(['throttle:1000,1'])->post('help-childcategory-by-subcategoryid', [HelpChildcategoryController::class, 'getHelpChildcategoryBySubcategoryId']);




        // =========Help Art=======
        Route::middleware(['throttle:1000,1'])->get('help-article-list', [HelpArticleController::class, 'index']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-article-create', [HelpArticleController::class, 'store']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-article-update', [HelpArticleController::class, 'update']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-article-delete', [HelpArticleController::class, 'delete']);
        Route::middleware(['throttle:1000,1'])->get('get-help-article-by-id/{id}', [HelpArticleController::class, 'getdatabyId']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('help-article-bulk-delete', [HelpArticleController::class, 'bulkDelete']);
        Route::middleware(['throttle:1000,1'])->get('get-help-article',[HelpArticleController::class,'getArticles']);


        // ==========Like/Dislike===============


        Route::middleware(['throttle:1000,1'])->post('/help-activity', [HelpActivityController::class, 'manageActivity']);

        // =========Services=======
        Route::middleware(['throttle:1000,1'])->get('services-list', [servicescontroller::class, 'index']);
        Route::middleware(['throttle:1000,1'])->post('services-create', [servicescontroller::class, 'store']);
        Route::middleware(['throttle:1000,1'])->post('services-update', [servicescontroller::class, 'update']);
        Route::middleware(['throttle:1000,1'])->post('services', [servicescontroller::class, 'delete']);

        // =========Project=======
        Route::middleware(['auth.api.token'])->group(function () {
            Route::middleware(['throttle:1000,1'])->get('projects-list', [Projectcontroller::class, 'index']);
            Route::middleware(['throttle:1000,1'])->post('get-projectdata-byid', [Projectcontroller::class, 'show']);
            Route::middleware(['throttle:1000,1'])->post('projects-create', [Projectcontroller::class, 'store']);
            Route::middleware(['throttle:1000,1'])->post('projects-update', [Projectcontroller::class, 'update']);
            Route::middleware(['throttle:1000,1'])->post('projects-delete', [Projectcontroller::class, 'destroy']);
        });



        // =========Profile=======
        Route::middleware(['throttle:1000,1'])->post('complete-your-profile', [Profilecontroller::class, 'updateProfile']);


        // =====For Client Review=====
        Route::middleware(['api.token','throttle:1000,1'])->post('add-client-review', [ClientReviewController::class, 'store']);
        Route::middleware(['api.token','throttle:1000,1'])->post('edit-client-review', [ClientReviewController::class, 'update']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('delete-client-review', [ClientReviewController::class, 'destroy']);
        Route::middleware(['throttle:1000,1'])->get('get-client-review', [ClientReviewController::class, 'index']);
        Route::middleware(['api.token','throttle:1000,1'])->get('get-client-review-by-id/{id}', [ClientReviewController::class, 'getdatabyId']);

        // =====For Faq Category=====
        Route::middleware(['admin.token','throttle:1000,1'])->post('add-faq-category', [FaqCategoryController::class, 'store']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('edit-faq-category', [FaqCategoryController::class, 'update']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('delete-faq-category', [FaqCategoryController::class, 'destroy']); //Done By softtonia
        Route::middleware(['throttle:1000,1'])->get('get-faq-category', [FaqCategoryController::class, 'index']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->get('get-faq-category-by-id/{id}', [FaqCategoryController::class, 'getdatabyId']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('bulk-delete-faq-category', [FaqCategoryController::class, 'bulkDelete']);

        // =====For Faq =======
        Route::middleware(['admin.token','throttle:1000,1'])->post('add-faq', [FaqController::class, 'store']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('edit-faq', [FaqController::class, 'update']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->post('delete-faq', [FaqController::class, 'destroy']); //Done By softtonia
        Route::middleware(['throttle:1000,1'])->get('get-faq', [FaqController::class, 'index']); //Done By softtonia
        Route::middleware(['admin.token','throttle:1000,1'])->get('get-faq-by-id/{id}', [FaqController::class, 'getdatabyId']); //Done By softtonia

        // Otp Route
        Route::middleware(['api.token','throttle:1000,1'])->post('/verify-email-otp', [OtpController::class, 'emailVerifyOtp']);
        Route::middleware(['throttle:1000,1'])->post('send-otp', [OtpController::class, 'sendOtp']);
        Route::middleware(['throttle:1000,1'])->post('verify-otp', [OtpController::class, 'verifyOtp']);



        // With otp password forget
        Route::middleware(['throttle:1000,1'])->post('/generate-email-otp', [EmailOtpController::class, 'generateOtp']);
        Route::middleware(['throttle:1000,1'])->post('/reset-password', [EmailOtpController::class, 'resetPassword']);

        // Country, State, City Get

        Route::middleware(['throttle:1000,1'])->get('countries', [LocationController::class, 'getCountries']);
        Route::middleware(['throttle:1000,1'])->get('states/{countryId}', [LocationController::class, 'getStatesByCountry']);
        Route::middleware(['throttle:1000,1'])->get('cities/{stateId}', [LocationController::class, 'getCitiesByState']);

         Route::middleware(['admin.token','throttle:1000,1'])->get('/get-location-countries', [LocationController::class, 'getLocationCountries']);
         Route::middleware(['admin.token','throttle:1000,1'])->get('/get-location-states', [LocationController::class, 'getLocationStates']);
         Route::middleware(['admin.token','throttle:1000,1'])->get('/get-location-cities', [LocationController::class, 'getLocationCities']);

         Route::middleware(['admin.token','throttle:1000,1'])->post('/cities/{id}/update-flags', [LocationController::class, 'updateCityFlags']);

         Route::middleware(['admin.token','throttle:1000,1'])->get('/export-location-csv', [LocationController::class, 'locationExportToCSV']);





        Route::middleware(['allrole.token','throttle:1000,1'])->post('business-role-update-profile', [UserController::class, 'updateProfile']);






        // Template  Id

        // CustomFieldUniqueCode
        Route::middleware(['admin.token','throttle:1000,1'])->post('add-template-id-listings', [CustomFieldController::class, 'storeCustomFieldUniqueCode']);
        Route::middleware(['throttle:1000,1'])->get('/get-template-id-listings-by-id', [CustomFieldController::class, 'showCustomFieldUniqueCodeById']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('update-template-id-listings', [CustomFieldController::class, 'updateCustomFieldUniqueCode']);
        Route::middleware(['admin.token','throttle:1000,1'])->delete('delete-template-id-listings', [CustomFieldController::class, 'destroyCustomFieldUniqueCode']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('bulk-delete-template-id-listings', [CustomFieldController::class, 'bulkDeleteCustomFieldUniqueCode']);


        // Top Features for project developer and listings
        Route::middleware(['throttle:1000,1'])->get('top-features-list', [TopFeatureController::class, 'index']);
        Route::middleware(['throttle:1000,1'])->get('/get-top-features-by-id', [TopFeatureController::class, 'getTopFeaturesById']);
        Route::middleware(['admin.token','throttle:1000,1'])->post('create-or-update-top-feature/{id?}', [TopFeatureController::class, 'createOrUpdateTopFeature']);

        // Menu Managements

        Route::prefix('menus')->group(function () {
            Route::middleware(['throttle:1000,1'])->get('/', [MenuController::class, 'index']);
            Route::middleware(['throttle:1000,1'])->get('/show/{id}', [MenuController::class, 'show']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/store', [MenuController::class, 'store']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/update/{id}', [MenuController::class, 'update']);
            Route::middleware(['admin.token','throttle:1000,1'])->delete('/delete/{id}', [MenuController::class, 'destroy']);
            Route::middleware(['admin.token','throttle:1000,1'])->post('/reorder', [MenuController::class, 'reorder']); // Nested reorder
        });


            // Connection lifecycle
        Route::middleware(['throttle:1000,1'])->post('/connections', [ConnectionController::class, 'store']);         // Send connection request
        Route::middleware(['throttle:1000,1'])->post('/connections/{connection}/accept', [ConnectionController::class, 'accept']);   // Accept
        Route::middleware(['throttle:1000,1'])->post('/connections/{connection}/reject', [ConnectionController::class, 'reject']);   // Reject
        Route::middleware(['throttle:1000,1'])->delete('/connections/{connection}', [ConnectionController::class, 'destroy']);       // Cancel or Leave

        // Associations (connected users by role)
        Route::middleware(['throttle:1000,1'])->get('/my/associations', [UserAssociationController::class, 'associations']);
        Route::middleware(['throttle:1000,1'])->get('/my/consultancies', [UserAssociationController::class, 'consultancies']);
        Route::middleware(['throttle:1000,1'])->get('/my/companies', [UserAssociationController::class, 'companies']);
        Route::middleware(['throttle:1000,1'])->get('/my/agents', [UserAssociationController::class, 'agents']);
        Route::middleware(['throttle:1000,1'])->get('/my/developers', [UserAssociationController::class, 'developers']);


        // Get all leads
    Route::middleware(['throttle:1000,1'])->post('/leads/send-otp', [LeadController::class, 'sendOtp']);
    Route::middleware(['admin.token','throttle:1000,1'])->get('/leads', [LeadController::class, 'index']);
    Route::middleware(['throttle:1000,1'])->post('/leads', [LeadController::class, 'store']);
    Route::middleware(['admin.token','throttle:1000,1'])->get('/leads/{id}', [LeadController::class, 'show']);
    Route::middleware(['admin.token','throttle:1000,1'])->post('/leads/update/{id}', [LeadController::class, 'update']);
    Route::middleware(['admin.token','throttle:1000,1'])->delete('/leads/{id}', [LeadController::class, 'destroy']);

    Route::middleware(['throttle:1000,1'])->get('/get-assign-lead-to-user', [LeadController::class, 'assignUserLead']);

    // Lead Types

    Route::middleware(['throttle:1000,1'])->get('/lead-types', [LeadTypeController::class, 'index']);     // Get all
    Route::middleware(['throttle:1000,1'])->get('/lead-types/check-slug-unique', [LeadTypeController::class, 'checkSlugUnique']);
    Route::middleware(['admin.token','throttle:1000,1'])->get('/lead-types/search', [LeadTypeController::class, 'searchLeadType']);
    Route::middleware(['throttle:1000,1'])->get('/lead-types/{id}', [LeadTypeController::class, 'show']); // Get single
    Route::middleware(['admin.token','throttle:1000,1'])->post('/lead-types', [LeadTypeController::class, 'store']);    // Create
    Route::middleware(['admin.token','throttle:1000,1'])->post('/lead-types-update/{id}', [LeadTypeController::class, 'update']); // Update
    Route::middleware(['admin.token','throttle:1000,1'])->delete('/lead-types/{id}', [LeadTypeController::class, 'destroy']); // Delete
    Route::middleware(['throttle:1000,1'])->post('/lead-types/search-by-name', [LeadTypeController::class, 'getSearchByName']);
    Route::middleware(['throttle:1000,1'])->post('/lead-types/search-by-slug', [LeadTypeController::class, 'getSearchBySlug']);


    Route::middleware(['admin.token','throttle:1000,1'])->get('contact-us-leads', [ContactUsLeadController::class, 'index']);   // List with pagination
    Route::middleware(['throttle:1000,1'])->post('contact-us-leads', [ContactUsLeadController::class, 'store']); // Create
    Route::middleware(['admin.token','throttle:1000,1'])->get('contact-us-leads/{id}', [ContactUsLeadController::class, 'show']); // Show single
    Route::middleware(['admin.token','throttle:1000,1'])->put('contact-us-leads/{id}', [ContactUsLeadController::class, 'update']); // Update
    Route::middleware(['admin.token','throttle:1000,1'])->delete('contact-us-leads/{id}', [ContactUsLeadController::class, 'destroy']); // Delete
     Route::middleware(['admin.token','throttle:1000,1'])->post('contact-us-leads/bulk-delete', [ContactUsLeadController::class, 'bulkDestroy']); // Delete
    Route::middleware(['admin.token','throttle:1000,1'])->post('/contact-us-leads/{id}/status', [ContactUsLeadController::class, 'updateStatus']);
    Route::middleware(['admin.token','throttle:1000,1'])->post('contact-us-leads/search', [ContactUsLeadController::class, 'contactUsLeadSearch']);


    });



    Route::middleware(['throttle:1000,1'])->get('/developer-listings-by-featured-type', [TopFeatureController::class, 'getDevelopersByFeaturedType']);
    Route::middleware(['throttle:1000,1'])->get('/properties-listing-by-featured-type', [TopFeatureController::class, 'getPropertiesByFeaturedType']);
    Route::middleware(['throttle:1000,1'])->get('/project-listings-by-featured-type', [TopFeatureController::class, 'getProjectsByFeaturedType']);

    // API Client

    Route::middleware('admin.token','throttle:1000,1')->get('api-client-secrect-list', [ApiClientController::class, 'index']);
    Route::middleware('admin.token','throttle:1000,1')->post('api-client-secrect-store', [ApiClientController::class, 'store']);
    Route::middleware('admin.token','throttle:1000,1')->get('api-client-secrect-show-by-id/{id}', [ApiClientController::class, 'show']);
    Route::middleware('admin.token','throttle:1000,1')->post('api-client-secrect-update/{id}', [ApiClientController::class, 'update']);
    Route::middleware('admin.token','throttle:1000,1')->post('api-client-secrect-delete/{id}', [ApiClientController::class, 'destroy']);

    Route::middleware('admin.token','throttle:1000,1')->get('generate-api-client-id', [ApiClientController::class, 'generateApiClientId']);
    Route::middleware('admin.token','throttle:1000,1')->get('generate-api-client-secret', [ApiClientController::class, 'generateApiClientSecret']);
    Route::middleware('admin.token','throttle:1000,1')->get('generate-next-js-internal-key', [ApiClientController::class, 'generateNextJsInternalKey']);
    Route::middleware('admin.token','throttle:1000,1')->get('api-client-secrect-app-types', [ApiClientController::class, 'getAppTypes']);

    Route::middleware('admin.token','throttle:1000,1')->get('api-client-secrect-show-by-app-types/{appType}', [ApiClientController::class, 'showByAppType']);
    Route::middleware('admin.token','throttle:1000,1')->get('api-client-secrect-export-csv/{id}', [ApiClientController::class, 'exportCsvApiClient']);

    // IpLog

    Route::middleware(['admin.token','throttle:1000,1'])->get('admin/ip-logs', [IpLogController::class, 'index']);
    Route::middleware(['admin.token','throttle:1000,1'])->post('admin/ip-logs-update-status', [IpLogController::class, 'updateIpStatus']);

    Route::middleware(['admin.token','throttle:1000,1'])->get('admin/ip-logs-by-ip-address', [IpLogController::class, 'getByIpAddress']);
    Route::middleware(['admin.token','throttle:1000,1'])->get('admin/ip-logs-by-user-id', [IpLogController::class, 'getByUserId']);
    Route::middleware(['admin.token','throttle:1000,1'])->get('admin/ip-logs-by-id', [IpLogController::class, 'getById']);
    Route::middleware(['admin.token','throttle:1000,1'])->post('admin/ip-logs-update-status-by-ip', [IpLogController::class, 'updateStatusByIp']);


    




Route::get('auth/google', [GoogleAuthController::class, 'redirectToGoogle']);
Route::get('auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback']);
