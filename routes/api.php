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
use App\Http\Controllers\Api\CustomFieldGroupController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\EmailOtpController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\CustomField\CustomFieldExportImportController;

use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Connections\UserAssociationController;

use App\Http\Controllers\Auth\Kyc\KycController;

use App\Http\Controllers\Keyword\KeywordController;
use App\Http\Controllers\BusinessEnquiry\BusinessEnquiryController;
use App\Http\Controllers\Template\CustomWidgetController;
use App\Http\Controllers\Template\TemplateController;
use App\Http\Controllers\Template\TemplateBuilderController;
use App\Http\Controllers\Template\TemplateDisplayConditionController;
use App\Http\Controllers\Template\TemplateComponentController;
use App\Http\Controllers\Template\TemplateApiController;

use App\Http\Controllers\Api\PostTypeController;
use App\Http\Controllers\Api\DynamicPostController;
use App\Http\Controllers\Api\TaxonomyController;
use App\Http\Controllers\Api\TaxonomyTermController;
use App\Http\Controllers\Api\DynamicCustomFieldController;
use App\Http\Controllers\Api\PostTaxonomyTermController;

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


    // ======Project Listing============
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->post('add-project-listing', [ProjectlistingController::class, 'store']);
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->post('edit-project-listing', [ProjectlistingController::class, 'update']);
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->post('delete-project-listing', [ProjectlistingController::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('get-all-project-listing-by-admin', [ProjectlistingController::class, 'indexByAdmin']);

    Route::middleware(['throttle:60,1', 'allow.admin_company'])->get('get-data-project/{id}', [ProjectlistingController::class, 'getdatabyId']);
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->post('update-project-status', [ProjectlistingController::class, 'updateProjectStatus']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-project-status-by-admin', [ProjectlistingController::class, 'updateProjectStatusByAdmin']);

    Route::middleware(['throttle:60,1', 'allow.admin_company'])->post('project-bulk-delete', [ProjectlistingController::class, 'bulkDelete']);

    Route::middleware(['throttle:60,1', 'allow.admin_company'])->post('update-project-temporary-status', [ProjectlistingController::class, 'updateTemporaryStatus']);
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->get('project-search', [ProjectlistingController::class, 'projectSearch']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('/project-listings/{id}/update-complete-status', [ProjectlistingController::class, 'completeStatusUpdate']);
    # 13 Oct 2025S
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->get('/get-associated-developer-with-project/{project_id}', [ProjectlistingController::class, 'getAssociatedDeveloperWithProject']);
    # 14 Oct 2025
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->get('/get-all-project-listings-by-companyoradmin-token', [ProjectlistingController::class, 'getAllProjectsListingByCompanyOrAdminToken']);
    ### No Auth ###

    # 13 Oct 2025 Project / Developer Ongoing / Completed API
    Route::get('get-all-ongoing-projects-by-developer', [ProjectlistingController::class, 'getOngoingProjectsByDeveloper'])->middleware(['throttle:60,1']);
    Route::get('get-all-completed-projects-by-developer', [ProjectlistingController::class, 'getCompletedProjectsByDeveloper'])->middleware(['throttle:60,1']);




    Route::get('get-project-by-user-id-filter-by-purpose/{userId}', [ProjectlistingController::class, 'getProjectsByUserId'])->middleware(['throttle:60,1']);
    # 16 Oct 2025
    Route::get('get-near-by-projects/{projectId}', [ProjectlistingController::class, 'getNearByProject'])->middleware(['throttle:60,1']);
    Route::get('get-other-projects/{projectId}', [ProjectlistingController::class, 'getOtherProject'])->middleware(['throttle:60,1']);

    Route::get('get-current-property-by-company-project', [ProjectlistingController::class, 'getCurrentPropertyByCompanyProject'])->middleware(['throttle:60,1']);

    // ======Developer Listing============
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->post('add-developer-listing', [DeveloperlistingController::class, 'store']);
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->post('edit-developer-listing', [DeveloperlistingController::class, 'update']);
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->post('developer-delete', [DeveloperlistingController::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('fetch-all-developer-listing-by-admin', [DeveloperlistingController::class, 'indexByAdmin']);
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->get('get-data-developer/{id}', [DeveloperlistingController::class, 'getdatabyId']);
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->post('developer-bulk-delete', [DeveloperlistingController::class, 'bulkDelete']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-developer-status', [DeveloperlistingController::class, 'updateDeveloperStatus']);
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->get('/user-developer', [DeveloperlistingController::class, 'getUserDeveloper']);
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->post('get-all-developer-by-location-id', [DeveloperlistingController::class, 'getAllDeveloperByLocationId']);

    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->post('update-developer-temporary-status', [DeveloperlistingController::class, 'updateTemporaryStatus']);
    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->get('/developer-search', [DeveloperlistingController::class, 'developerSearch']);

    # 13 Oct 2025S

    Route::middleware(['throttle:60,1', 'allow.admin_developer'])->get('get-associated-projects-with-developer', [DeveloperlistingController::class, 'getAssociatedProjectsWithDeveloper']);


    ### No Auth ###


    Route::get('get-developer-by-user-id-filter-by-purpose/{userId}', [DeveloperlistingController::class, 'getDevelopersByUserId'])->middleware(['throttle:60,1']);
    Route::get('get-related-developers-id/{developerId}', [DeveloperlistingController::class, 'getRelatedDevelopersByDeveloperId'])->middleware(['throttle:60,1']);




    // =======Property Listing============
    Route::middleware(['throttle:60,1', 'allow.property.listing'])->post('add-properties-listing', [PropertylistingController::class, 'store']);
    Route::middleware(['throttle:60,1', 'allow.property.listing'])->post('edit-properties-listing', [PropertylistingController::class, 'update']);
    Route::middleware(['throttle:60,1', 'allow.property.listing'])->post('delete-properties-listing', [PropertylistingController::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'allow.property.listing'])->get('get-all-properties-listing', [PropertylistingController::class, 'indexByadmin']);

    Route::middleware(['throttle:60,1', 'api.token'])->get('properties-search', [PropertylistingController::class, 'propertiesSearch']);

    Route::middleware(['throttle:60,1', 'allow.property.listing'])->get('get-data-properties/{id}', [PropertylistingController::class, 'getdatabyId']);
    Route::middleware(['throttle:60,1', 'allow.property.listing'])->post('update-temporary-status', [PropertylistingController::class, 'updateTemporaryStatus']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('get-temporary-statuses', [PropertylistingController::class, 'getTemporaryStatuses']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('get-property-statuses', [PropertylistingController::class, 'getPropertyStatuses']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-property-status', [PropertylistingController::class, 'updatePropertyStatus']);
    Route::middleware(['throttle:60,1', 'api.token'])->post('get-all-project-by-location-id', [PropertylistingController::class, 'getAllProjectByLocationId']);
    Route::middleware(['throttle:60,1', 'allow.admin_company'])->post('get-company-project-by-location-id', [PropertylistingController::class, 'getComapnyProjectByLocationId']);
    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('properties-bulk-delete', [PropertylistingController::class, 'bulkDelete']);
    //
    #### No Auth ######

    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->get('/user-properties', [PropertylistingController::class, 'getUserProperties']);

    Route::get('/get-property-by-user-id-filter-by-purpose/{userId}', [PropertylistingController::class, 'getPropertyByUserId'])->middleware(['throttle:60,1']);
    Route::get('/get-related-properties-id/{propertyId}', [PropertylistingController::class, 'getRelatedPropertiesByPropertyId'])->middleware(['throttle:60,1']);


    // Start Website Route

    // Locations
    Route::get('/locations', [LocationController::class, 'getCityGroups'])->middleware(['throttle:60,1']);
    Route::get('/get-localities-filter-by-location-id', [LocationController::class, 'getAreaLocalities'])->middleware(['throttle:60,1']);
    // Project Listing No Auth
    Route::get('get-all-project-listing-no-auth', [ProjectlistingController::class, 'index'])->middleware(['throttle:60,1']);
    Route::get('get-data-project-no-auth/{id}', [ProjectlistingController::class, 'getdatabyIdNoAuth'])->middleware(['throttle:60,1']);

    // Developer Listing No Auth
    Route::get('fetch-all-developer-listing-no-auth', [DeveloperlistingController::class, 'index'])->middleware(['throttle:60,1']);
    Route::get('get-data-developer-no-auth/{id}', [DeveloperlistingController::class, 'getdatabyIdNoAuth'])->middleware(['throttle:60,1']);

    // Property Listing No Auth
    Route::get('get-all-properties-listing-no-auth', [PropertylistingController::class, 'index'])->middleware(['throttle:60,1']);
    Route::get('get-data-properties-no-auth/{id}', [PropertylistingController::class, 'getdatabyIdNoAuth'])->middleware(['throttle:60,1']);

    // End Website Route


    // frontend site
    // =======Front Property Listing============


    Route::post('store-property-analytics', [frontPropertylistingController::class, 'storePropertyAnalytics'])->middleware(['throttle:60,1']);
    Route::get('list-property-analytics', [frontPropertylistingController::class, 'listPropertyAnalytics'])->middleware(['throttle:60,1']);
    Route::get('view-property-analytics', [frontPropertylistingController::class, 'viewPropertyAnalytics'])->middleware(['throttle:60,1']);
    // frontend side
    // =======Front Project Listing============
    Route::post('add-website-project-listing', [frontProjectlistingController::class, 'store'])->middleware(['throttle:60,1']);
    Route::post('edit-website-project-listing', [frontProjectlistingController::class, 'update'])->middleware(['throttle:60,1']);
    Route::post('delete-website-project-listing', [frontProjectlistingController::class, 'destroy'])->middleware(['throttle:60,1']);
    Route::get('get-all-website-project-listing', [frontProjectlistingController::class, 'index'])->middleware(['throttle:60,1']);
    Route::get('get-data-website-project/{id}', [frontProjectlistingController::class, 'getdatabyId'])->middleware(['throttle:60,1']);
    Route::post('update-website-project-status', [frontProjectlistingController::class, 'updateProjectStatus'])->middleware(['throttle:60,1']);
    Route::post('store-project-analytics', [frontProjectlistingController::class, 'storeProjectAnalytics'])->middleware(['throttle:60,1']);
    Route::get('list-project-analytics', [frontProjectlistingController::class, 'listProjectAnalytics'])->middleware(['throttle:60,1']);
    Route::get('view-project-analytics', [frontProjectlistingController::class, 'viewProjectAnalytics'])->middleware(['throttle:60,1']);
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

    Route::middleware(['throttle:60,1', 'admin.token'])->post('import-keywords', [KeywordController::class, 'import']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('export-keywords', [KeywordController::class, 'export']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('search-keywords', [KeywordController::class, 'searchKeywordList']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('fetch-keywords', [KeywordController::class, 'fetchKeywordList']);


    Route::middleware(['throttle:60,1', 'api.token'])->get('get-keyword-by-keyword-type', [Admincontroller::class, 'getKeywordbykeywordtype']);
    // ======= Analytics =========
    Route::middleware(['throttle:60,1', 'admin.token'])->get('admin-dashboard-analytics', [AdminDashboardAnalyticsController::class, 'adminDashboardAnalytics']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('business-dashboard-analytics', [BusinessDashboardAnalyticsController::class, 'businessDashboardAnalytics']);
    Route::middleware(['throttle:60,1', 'allow.owner.role'])->get('owner-dashboard-analytics', [OwnerDashboardAnalyticsController::class, 'ownerDashboardAnalytics']);

    // =======Location============

    Route::get('/all-location-list', [LocationController::class, 'locationList'])->middleware(['throttle:60,1']);




    // ======= Bulk Upload Country , State, City in CSV Format ===========

    Route::middleware(['throttle:60,1', 'admin.token'])->post('bulk-upload-location-csv', [Locationcontroller::class, 'bulkUploadCSC']);

    // =======Amenity============


    Route::middleware(['throttle:60,1', 'admin.token'])->post('amenity-create', [Amenitycontroller::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('amenity-update', [Amenitycontroller::class, 'update']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('amenity-listing', [Amenitycontroller::class, 'index']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('amenity', [Amenitycontroller::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'api.token'])->post('getdatabyId-amenity', [Amenitycontroller::class, 'getdatabyId']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('search-amenity-listing', [Amenitycontroller::class, 'searchByName']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('amenity-bulk-delete', [Amenitycontroller::class, 'bulkDelete']);


    // =======Property Type============


    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-type-create', [Propertytypecontroller::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-type-update', [Propertytypecontroller::class, 'update']);
    Route::get('property-type-listing', [Propertytypecontroller::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-type-delete', [Propertytypecontroller::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'api.token'])->post('getdatabyId-property-type', [Propertytypecontroller::class, 'getdatabyId']);


    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-type-bulk-delete', [Propertytypecontroller::class, 'bulkDelete']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('property-type-search', [Propertytypecontroller::class, 'searchByName'])->name('propertytype.search');


    // =======Status============
    Route::middleware(['throttle:60,1', 'admin.token'])->post('status-create', [statuscontroller::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('status-update', [statuscontroller::class, 'update']);
    Route::get('status-listing', [statuscontroller::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('status', [statuscontroller::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('getdatabyId-status', [statuscontroller::class, 'getdatabyId']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('status-bulk-delete', [statuscontroller::class, 'bulkDelete']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('status-search', [statuscontroller::class, 'searchByName'])->name('status.search');

    // =======Purpose============

    Route::middleware(['validate.api.client'])->group(function () {

        Route::middleware(['throttle:60,1', 'admin.token'])->post('purpose-create', [PurposeController::class, 'store']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('purpose-update', [PurposeController::class, 'update']);
        Route::get('purpose-listing', [PurposeController::class, 'index'])->middleware(['throttle:60,1']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('purpose-delete', [PurposeController::class, 'destroy']);
        Route::middleware(['throttle:60,1', 'api.token'])->post('getdatabyId-purpose', [PurposeController::class, 'getdatabyId']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('purpose-bulk-delete', [PurposeController::class, 'bulkDelete']);
        Route::middleware(['throttle:60,1', 'api.token'])->get('purpose-search', [PurposeController::class, 'searchByName'])->name('purposes.search');
    });


    // =======Property============
    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-create', [Propertycontroller::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-update', [Propertycontroller::class, 'update']);
    Route::get('property-listing', [Propertycontroller::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-delete', [Propertycontroller::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('properties/{id}', [PropertyController::class, 'getPropertyAndType']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('property-bulk-delete', [PropertyController::class, 'bulkDelete']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('property-search', [PropertyController::class, 'searchByName'])->name('property.search');



    // =======Amenity Categories============

    Route::middleware(['throttle:60,1', 'admin.token'])->post('add-amenities-categories', [AmenitycategoriesController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('edit-amenities-categories', [AmenitycategoriesController::class, 'update']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('list-amenities-categories', [AmenitycategoriesController::class, 'index']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('delete-amenities-categories', [AmenitycategoriesController::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'api.token'])->post('getdatabyId-amenitycategories', [AmenitycategoriesController::class, 'getdatabyId']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('amenities-categories-bulk-delete', [AmenitycategoriesController::class, 'bulkDelete']);

    Route::middleware(['throttle:60,1', 'api.token'])->get('search-amenities-categories', [AmenitycategoriesController::class, 'searchByName']);

    // admin route will end from here


    // custom field will start from here
    Route::get('custom-field-listing-by-type', [CustomFieldController::class, 'customFieldListingByType'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('add-custom-fields', [CustomFieldController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('edit-custom-fields-by-group-id', [CustomFieldController::class, 'updateCustomFieldByGroupId']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('delete-custom-fields', [CustomFieldController::class, 'delete']);
    Route::post('get-custom-fields-by-group-id', [CustomFieldController::class, 'getCustomFieldByGroupId'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->get('model-listing', [CustomFieldController::class, 'modelListing']);
    Route::get('all_template_id_listings', [CustomFieldController::class, 'customFieldUniqueCode'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->get('condition-listing', [CustomFieldController::class, 'conditionListing']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->get('custom-field-listing', [CustomFieldController::class, 'customFieldListing']);
    Route::get('property-type-listing-by-propertyid', [CustomFieldController::class, 'propertyTypeListingByPropertyId'])->middleware(['throttle:60,1']);
    Route::get('property-status-listing-by-propertytype', [CustomFieldController::class, 'propertyStatusListingByPropertyType'])->middleware(['throttle:60,1']);
    Route::get('get-amenities-data', [CustomFieldController::class, 'GetAmenitiesData'])->middleware(['throttle:60,1']);
    Route::post('get-custom-filded-list', [CustomFieldController::class, 'GetCustomFields'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-custom-field/{id}', [CustomFieldController::class, 'updateCustomField']);
    Route::post('custom-fields/search-and-filter', [CustomFieldController::class, 'searchAndFilter'])->middleware(['throttle:60,1']);
    Route::post('custom-fields/delete-custom-field', [CustomFieldController::class, 'deleteCustomField'])->middleware(['throttle:60,1']);
    Route::post('slug-uniqueness-check', [CustomFieldController::class, 'slugUniquesCheck'])->middleware(['throttle:60,1']);
    Route::get('get-model-condition-record', [CustomFieldController::class, 'getAllModelConditionRecords'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->get('get-custom-field-model-multi-condition', [CustomFieldController::class, 'getCustomFieldModelMultiCondition']);
    Route::post('custom-field-listing-by-model-conditionid', [CustomFieldController::class, 'customFieldListingByModelConditionId'])->middleware(['throttle:60,1']);

    Route::get('get-custom-field-by-id/{id}', [CustomFieldController::class, 'getCustomFieldById'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('edit-custom-fields-by-id/{id}', [CustomFieldController::class, 'updateCustomFieldById']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('delete-custom-fields-by-id', [CustomFieldController::class, 'deleteCustomFieldById']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('bulk-delete-custom-fields-by-id', [CustomFieldController::class, 'bulkDeleteCustomFieldByIds']);

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



    // Ticket Route will start from here
    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('tickets-create', [TicketController::class, 'store']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->get('tickets-list', [TicketController::class, 'index']);
    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('tickets-update', [TicketController::class, 'update']);
    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('tickets-delete', [TicketController::class, 'destroy']);
    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('tickets-bulk-delete', [TicketController::class, 'bulkDestroy']);
    Route::middleware(['throttle:60,1', 'allrole.token'])->post('get-tickets-by-id', [TicketController::class, 'show']);
    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('tickets-search', [TicketController::class, 'searchByTicketNumber']);


    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('get-tickets-by-token', [TicketController::class, 'getTicketByToken']);

    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->post('update-tickets-status', [TicketController::class, 'updateTicketStatus']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-status-create', [ticketstatuscontroller::class, 'store']);  //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-status-update', [ticketstatuscontroller::class, 'update']); //Done By softtonia
    Route::get('tickets-status-list', [ticketstatuscontroller::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-status-delete', [ticketstatuscontroller::class, 'destroy']); //Done By softtonia

    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-status-bulk-delete', [ticketstatuscontroller::class, 'bulkDelete']);
    Route::get('search-tickets-status-name', [ticketstatuscontroller::class, 'searchTicketStatusName'])->middleware(['throttle:60,1']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('get-tickets-status-byid', [ticketstatuscontroller::class, 'show']); //Done By softtonia

    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-department-create', [TicketDepartmentController::class, 'store']);  //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-department-update', [TicketDepartmentController::class, 'update']); //Done By softtonia
    Route::get('tickets-department-list', [TicketDepartmentController::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('search-tickets-department-list', [TicketDepartmentController::class, 'searchDepartment']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-department-delete', [TicketDepartmentController::class, 'destroy']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('get-tickets-department-byid', [TicketDepartmentController::class, 'show']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-department-bulk-delete', [TicketDepartmentController::class, 'bulkDestroy']);


    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-priority-create', [ticketprioritycontroller::class, 'store']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-priority-update', [ticketprioritycontroller::class, 'update']); //Done By softtonia
    Route::middleware(['throttle:60,1', 'admin.token'])->get('tickets-priority-list', [ticketprioritycontroller::class, 'index']); //Done By softtonia
    Route::post('tickets-priority-delete', [ticketprioritycontroller::class, 'destroy'])->middleware(['throttle:60,1']); //Done By softtonia
    Route::post('tickets-priority-bulk-delete', [ticketprioritycontroller::class, 'bulkDelete'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('get-tickets-priority-byid', [ticketprioritycontroller::class, 'show']); //Done By softtonia

    Route::middleware(['throttle:60,1', 'admin.token'])->get('search-tickets-priority', [ticketprioritycontroller::class, 'searchTicketPriority']);

    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-type-create', [TicketTypeController::class, 'store']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-type-update', [TicketTypeController::class, 'update']);
    Route::get('tickets-type-list', [TicketTypeController::class, 'index'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('tickets-type-delete', [TicketTypeController::class, 'destroy']);
    Route::post('get-tickets-type-byid', [TicketTypeController::class, 'show'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->delete('/tickets-type-bulk-delete', [TicketTypeController::class, 'bulkDelete']);
    Route::get('search-tickets-type', [TicketTypeController::class, 'searchTicketType'])->middleware(['throttle:60,1']);


    Route::middleware(['throttle:60,1', 'allrole.token'])->post('tickets/respond', [TicketController::class, 'respond']);
    Route::get('tickets-respond-list', [TicketController::class, 'respondlist'])->middleware(['throttle:60,1']);
    // ticket response history
    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->get('/tickets-response-list-history/{ticketId}', [TicketController::class, 'ticketResponseHistory']);

    // Ticket Route will end from here

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

    // =========Project=======
    Route::middleware(['auth.api.token'])->group(function () {
        Route::get('projects-list', [Projectcontroller::class, 'index'])->middleware(['throttle:60,1']);
        Route::post('get-projectdata-byid', [Projectcontroller::class, 'show'])->middleware(['throttle:60,1']);
        Route::post('projects-create', [Projectcontroller::class, 'store'])->middleware(['throttle:60,1']);
        Route::post('projects-update', [Projectcontroller::class, 'update'])->middleware(['throttle:60,1']);
        Route::post('projects-delete', [Projectcontroller::class, 'destroy'])->middleware(['throttle:60,1']);
    });



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
    Route::middleware(['throttle:60,1', 'api.token'])->post('/verify-email-otp', [OtpController::class, 'emailVerifyOtp']);
    Route::middleware(['throttle:60,1', 'api.token'])->get('/resend-email-otp', [OtpController::class, 'resendOtp']);




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

    // CustomFieldUniqueCode
    Route::middleware(['throttle:60,1', 'admin.token'])->get('export-template-id-listings', [CustomFieldController::class, 'exportCustomFieldUniqueCode']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('import-template-id-listings', [CustomFieldController::class, 'importCustomFieldUniqueCode']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('template-id-listings-search', [CustomFieldController::class, 'searchCustomFieldUniqueCode']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('template-id-listings-filter', [CustomFieldController::class, 'filterCustomFieldUniqueCodeByType']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('template-id-listings-by-type', [CustomFieldController::class, 'customFieldUniqueCodeByType']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('add-template-id-listings', [CustomFieldController::class, 'storeCustomFieldUniqueCode']);
    Route::get('/get-template-id-listings-by-id', [CustomFieldController::class, 'showCustomFieldUniqueCodeById'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('update-template-id-listings', [CustomFieldController::class, 'updateCustomFieldUniqueCode']);
    Route::middleware(['throttle:60,1', 'admin.token'])->delete('delete-template-id-listings', [CustomFieldController::class, 'destroyCustomFieldUniqueCode']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('bulk-delete-template-id-listings', [CustomFieldController::class, 'bulkDeleteCustomFieldUniqueCode']);



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

Route::middleware((['admin.token', 'validate.api.client']))->get('api-client-secrect-list', [ApiClientController::class, 'index']);
Route::middleware('admin.token')->post('api-client-secrect-store', [ApiClientController::class, 'store']);
Route::middleware((['admin.token', 'validate.api.client']))->get('api-client-secrect-show-by-id/{id}', [ApiClientController::class, 'show']);
Route::middleware('admin.token')->post('api-client-secrect-update/{id}', [ApiClientController::class, 'update']);
Route::middleware('admin.token')->post('api-client-secrect-delete/{id}', [ApiClientController::class, 'destroy']);

Route::middleware('admin.token')->get('generate-api-client-id', [ApiClientController::class, 'generateApiClientId']);
Route::middleware('admin.token')->get('generate-api-client-secret', [ApiClientController::class, 'generateApiClientSecret']);
Route::middleware('admin.token')->get('generate-next-js-internal-key', [ApiClientController::class, 'generateNextJsInternalKey']);
Route::middleware('admin.token')->get('api-client-secrect-app-types', [ApiClientController::class, 'getAppTypes']);

Route::middleware('admin.token')->get('api-client-secrect-show-by-app-types/{appType}', [ApiClientController::class, 'showByAppType']);
Route::middleware('admin.token')->get('api-client-secrect-export-csv/{id}', [ApiClientController::class, 'exportCsvApiClient']);

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




Route::get('auth/google', [GoogleAuthController::class, 'redirectToGoogle'])->middleware(['throttle:60,1']);
Route::get('auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->middleware(['throttle:60,1']);



// ================= Admin CRM Template Builder APIs =================

// Route::middleware(['validate.api.client'])->group(function () {

Route::middleware(['throttle:60,1', 'admin.token'])->group(function () {

    // Templates
    Route::get('templates-list', [TemplateController::class, 'index']);
    Route::post('templates-create', [TemplateController::class, 'create']);
    Route::get('templates-show/{id}', [TemplateController::class, 'show']);
    Route::post('templates-update/{id}', [TemplateController::class, 'update']);
    Route::post('templates-update-status/{id}', [TemplateController::class, 'updateStatus']);
    Route::delete('templates-delete/{id}', [TemplateController::class, 'destroy']);

    // Template Display Conditions
    Route::get('template-conditions-list/{template_id}', [TemplateDisplayConditionController::class, 'index']);
    Route::post('template-conditions-create', [TemplateDisplayConditionController::class, 'create']);
    Route::post('template-conditions-update', [TemplateDisplayConditionController::class, 'update']);
    Route::delete('template-conditions-delete/{id}', [TemplateDisplayConditionController::class, 'destroy']);

    // Template Builder Layout
    Route::get('template-builder-show/{template_id}', [TemplateBuilderController::class, 'show']);
    Route::post('template-builder-save/{template_id}', [TemplateBuilderController::class, 'save']);

    // Builder Draggable Components
    Route::get('template-components-list', [TemplateComponentController::class, 'index']);
    Route::post('template-components-create', [TemplateComponentController::class, 'create']);
    Route::post('template-components-update/{id}', [TemplateComponentController::class, 'update']);
    Route::delete('template-components-delete/{id}', [TemplateComponentController::class, 'destroy']);

    // Custom Widgets
    Route::get('custom-widgets/fields/{post_type}', [CustomWidgetController::class, 'fields']);
    Route::post('custom-widgets/configuration/save', [CustomWidgetController::class, 'saveConfiguration']);
    Route::get('custom-widgets-by-post-type', [CustomWidgetController::class, 'widgetsByPostType']);

    Route::get('custom-widgets', [CustomWidgetController::class, 'index']);
    Route::post('custom-widgets', [CustomWidgetController::class, 'store']);
    Route::get('custom-widgets/{id}', [CustomWidgetController::class, 'show']);
    Route::put('custom-widgets/{id}', [CustomWidgetController::class, 'update']);
    Route::delete('custom-widgets/{id}', [CustomWidgetController::class, 'destroy']);
});

Route::middleware(['throttle:60,1'])->post('template-resolve', [TemplateApiController::class, 'resolve']);
// });
// ================= Dynamic Post Type + Taxonomy APIs =================

Route::middleware(['throttle:60,1', 'admin.token'])->group(function () {

    // Post Types
    Route::get('post-types/trash', [PostTypeController::class, 'trash']);
    Route::post('post-types/bulk-delete', [PostTypeController::class, 'bulkDelete']);
    Route::post('post-types/bulk-restore', [PostTypeController::class, 'bulkRestore']);
    Route::delete('post-types/bulk-force-delete', [PostTypeController::class, 'bulkForceDelete']);
    Route::post('post-types/{id}/restore', [PostTypeController::class, 'restore']);
    Route::delete('post-types/{id}/force-delete', [PostTypeController::class, 'forceDelete']);
    Route::get('post-types/{id}/fields', [PostTypeController::class, 'fields']);
    Route::get('post-types-menu', [PostTypeController::class, 'menu']);

    Route::get('post-types/{postType}/fields', [PostTypeController::class, 'fields']);
    Route::get('post-types', [PostTypeController::class, 'index']);
    Route::post('post-types', [PostTypeController::class, 'store']);
    Route::get('post-types/{postType}', [PostTypeController::class, 'show']);
    Route::put('post-types/{postType}', [PostTypeController::class, 'update']);
    Route::delete('post-types/{postType}', [PostTypeController::class, 'destroy']);
    // Dynamic Posts
    Route::get('dynamic-posts/by-type/{slug}', [DynamicPostController::class, 'byPostType']);
    Route::post('dynamic-posts/bulk-delete', [DynamicPostController::class, 'bulkDelete']);
    Route::get('dynamic-posts', [DynamicPostController::class, 'index']);
    Route::post('dynamic-posts', [DynamicPostController::class, 'store']);
    Route::get('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'show']);
    Route::put('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'update']);
    Route::delete('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'destroy']);
    // Taxonomies
    Route::get('taxonomies/{taxonomy}/terms', [TaxonomyController::class, 'terms']);
    Route::get('taxonomies/{taxonomy}/fields', [TaxonomyController::class, 'fields']);
    Route::post('taxonomies/bulk-delete', [TaxonomyController::class, 'bulkDelete']);
    Route::get('taxonomies', [TaxonomyController::class, 'index']);
    Route::post('taxonomies', [TaxonomyController::class, 'store']);
    Route::get('taxonomies/{taxonomy}', [TaxonomyController::class, 'show']);
    Route::put('taxonomies/{taxonomy}', [TaxonomyController::class, 'update']);
    Route::delete('taxonomies/{taxonomy}', [TaxonomyController::class, 'destroy']);


    // Taxonomy Terms
    Route::post('taxonomy-terms/bulk-delete', [TaxonomyTermController::class, 'bulkDelete']);
    Route::get('taxonomy-terms', [TaxonomyTermController::class, 'index']);
    Route::post('taxonomy-terms', [TaxonomyTermController::class, 'store']);
    Route::get('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'show']);
    Route::put('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'update']);
    Route::delete('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'destroy']);


    // Dynamic Custom Fields
    Route::post('custom-fields/bulk-delete', [DynamicCustomFieldController::class, 'bulkDelete']);
    Route::get('custom-fields', [DynamicCustomFieldController::class, 'index']);
    Route::post('custom-fields', [DynamicCustomFieldController::class, 'store']);
    Route::get('custom-fields/{customField}', [DynamicCustomFieldController::class, 'show']);
    Route::put('custom-fields/{customField}', [DynamicCustomFieldController::class, 'update']);
    Route::delete('custom-fields/{customField}', [DynamicCustomFieldController::class, 'destroy']);
    Route::get('custom-fields/post-type/{postType}', [DynamicCustomFieldController::class, 'fieldsByPostType']);

    // Post Taxonomy Terms
    Route::post('post-taxonomy-terms/sync', [PostTaxonomyTermController::class, 'sync']);
    Route::post('post-taxonomy-terms/bulk-delete', [PostTaxonomyTermController::class, 'bulkDelete']);
    Route::get('post-taxonomy-terms', [PostTaxonomyTermController::class, 'index']);
    Route::post('post-taxonomy-terms', [PostTaxonomyTermController::class, 'store']);
    Route::get('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'show']);
    Route::put('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'update']);
    Route::delete('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'destroy']);

    Route::get('custom-field-groups', [CustomFieldGroupController::class, 'index']);
    Route::post('custom-field-groups', [CustomFieldGroupController::class, 'store']);
    Route::get('custom-field-groups/{id}', [CustomFieldGroupController::class, 'show']);
    Route::put('custom-field-groups/{id}', [CustomFieldGroupController::class, 'update']);
    Route::delete('custom-field-groups/{id}', [CustomFieldGroupController::class, 'destroy']);
    Route::post('custom-field-groups-bulk-delete', [CustomFieldGroupController::class, 'bulkDelete']);

    Route::get('custom-field-groups-by-post-type/{postType}', [CustomFieldGroupController::class, 'groupsByPostType']);
    Route::post('custom-field-groups-by-taxonomy/{taxonomy}', [CustomFieldGroupController::class, 'groupsByTaxonomy']);
});
