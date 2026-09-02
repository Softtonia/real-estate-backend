<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Admin\ApiClient\ApiClientController;
use App\Http\Controllers\AgentProject\AgentProjectController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CompanyConsultancy\CompanyConsultancyController;
use App\Http\Controllers\CompanyProject\CompanyProjectController;
use App\Http\Controllers\ConsultancyProject\ConsultancyProjectController;
use App\Http\Controllers\ContactUsLead\ContactUsLeadController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\HelpActivityController;
use App\Http\Controllers\IpLog\IpLogController;
use App\Http\Controllers\Lead\LeadController;
use App\Http\Controllers\Lead\LeadTypeController;
use App\Http\Controllers\OvervewAnalytics\AdminDashboardAnalyticsController;
use App\Http\Controllers\OvervewAnalytics\BusinessDashboardAnalyticsController;
use App\Http\Controllers\OvervewAnalytics\OwnerDashboardAnalyticsController;
use App\Http\Controllers\SearchEngine\SearchEngineController;
use App\Http\Controllers\SiteSetting\SiteSettingController;
use App\Http\Controllers\Subscribe\SubscribeController;
use App\Http\Controllers\Api\Frontend\CityExploreController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\Location\Locationcontroller;
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
use App\Http\Controllers\Profile\profilecontroller;
use App\Http\Controllers\ClientReviewController;
use App\Http\Controllers\FaqCategoryController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\Page\servicescontroller;
use App\Http\Controllers\Page\AboutusController;
use App\Http\Controllers\Help\HelpCategoryController;
use App\Http\Controllers\Help\HelpSubcategoryController;
use App\Http\Controllers\Help\HelpChildcategoryController;
use App\Http\Controllers\Help\HelpArticleController;
use App\Http\Controllers\Admin\MailConfigController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Api\Admin\FeaturedPropertyController;
use App\Http\Controllers\Api\Admin\Notification\AdminNotificationDeviceController;
use App\Http\Controllers\Api\Admin\Notification\AdminNotificationInboxController;
use App\Http\Controllers\Api\Admin\Notification\AdminNotificationReportController;
use App\Http\Controllers\Api\Admin\Notification\AdminNotificationRetryController;
use App\Http\Controllers\Api\Admin\Notification\AdminNotificationSendController;
use App\Http\Controllers\Api\Admin\Notification\AdminNotificationTemplateController;
use App\Http\Controllers\Api\Admin\Notification\AdminNotificationTopicController;
use App\Http\Controllers\Api\Admin\Notification\AdminUserNotificationsController;
use App\Http\Controllers\Api\Admin\Notification\NotificationConfigController;
use App\Http\Controllers\Api\Admin\UserActivityLogController;
use App\Http\Controllers\Api\Admin\UserListingController as AdminUserListingController;
use App\Http\Controllers\Api\Admin\Notification\NotificationPayloadOptionController;
use App\Http\Controllers\Api\Admin\Payment\PaymentGatewayController;
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
use App\Http\Controllers\Template\TemplateController;
use App\Http\Controllers\Template\TemplateBuilderController;
use App\Http\Controllers\Template\TemplateDisplayConditionController;

use App\Http\Controllers\Api\KeywordController;
use App\Http\Controllers\Api\PostTypeController;
use App\Http\Controllers\Api\PostTypeExportImportController;
use App\Http\Controllers\Api\TaxonomyController;
use App\Http\Controllers\Api\TaxonomyExportImportController;
use App\Http\Controllers\Api\TaxonomyTermController;
use App\Http\Controllers\Api\PostTaxonomyTermController;
use App\Http\Controllers\Api\CustomFieldGroupExportImportController;
use App\Http\Controllers\Api\DynamicPostController;
use App\Http\Controllers\Api\DynamicPostCsvController;
use App\Http\Controllers\Api\DynamicPostFormStepController;
use App\Http\Controllers\Api\Frontend\PropertySearchController;
use App\Http\Controllers\Api\FrontendLocationController;
use App\Http\Controllers\Api\Guest\GuestAgentController;
use App\Http\Controllers\Api\Guest\GuestDynamicPostController;
use App\Http\Controllers\Api\Guest\RecentlyViewedPostController;
use App\Http\Controllers\Api\KeywordExportController;
use App\Http\Controllers\Api\KeywordImportController;
use App\Http\Controllers\Api\Kyc\AdminKycController;
use App\Http\Controllers\Api\Kyc\KycSettingsController;
use App\Http\Controllers\Api\Kyc\UserKycController;
use App\Http\Controllers\Api\Membership\AdminMembershipAddonController;
use App\Http\Controllers\Api\Membership\AdminMembershipAddonOrderController;
use App\Http\Controllers\Api\Membership\AdminMembershipAuditLogController;
use App\Http\Controllers\Api\Membership\AdminMembershipCatalogController;
use App\Http\Controllers\Api\Membership\AdminMembershipCouponController;
use App\Http\Controllers\Api\Membership\AdminMembershipInvoiceController;
use App\Http\Controllers\Api\Membership\AdminMembershipRefundController;
use App\Http\Controllers\Api\Membership\AdminMembershipReportController;
use App\Http\Controllers\Api\Membership\AdminMembershipSettingController;
use App\Http\Controllers\Api\Membership\AdminMembershipTaxSettingController;
use App\Http\Controllers\Api\Membership\AdminMembershipUserController;
use App\Http\Controllers\Api\Membership\MembershipAccessController;
use App\Http\Controllers\Api\Membership\RazorpayWebhookController;
use App\Http\Controllers\Api\Membership\UserMembershipAddonController;
use App\Http\Controllers\Api\Membership\UserMembershipController;
use App\Http\Controllers\Api\Membership\UserMembershipFeatureUsageController;
use App\Http\Controllers\Api\Membership\UserMembershipInvoiceController;
use App\Http\Controllers\Api\Membership\UserMembershipNotificationController;
use App\Http\Controllers\Api\Notification\UserNotificationController;
use App\Http\Controllers\Api\Notification\UserNotificationDeviceController;
use App\Http\Controllers\Api\Notification\UserNotificationTopicController;
use App\Http\Controllers\Api\PageBuilder\DynamicFieldApiController;
use App\Http\Controllers\Api\PageBuilder\WidgetApiController;
use App\Http\Controllers\Api\PropertyAvailabilityController;
use App\Http\Controllers\Api\PropertyVerificationController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\UserListingController;
use App\Http\Controllers\Api\UserPropertyVerificationController;
use App\Http\Controllers\Frontend\FrontendListingController;
use App\Http\Controllers\Frontend\FrontendListingTaxonomyController;
use App\Http\Controllers\Template\PageBuilderContextController;
use App\Http\Controllers\Template\RelatedPostsWidgetCandidateController;
use App\Http\Controllers\Template\RelatedPostsWidgetPreviewController;
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
        'success' => true,
        'message' => 'IP checked successfully.',
        'data' => [
            'request' => [
                'app_type' => $request->header('X-App-Type'),
                'origin' => $request->header('Origin') ?: $request->header('X-App-Origin'),
                'ip' => $request->ip(),
            ],
        ],
    ]);
});

Route::middleware(['validate.api.client'])
    ->get('/app-access-check', function (Request $request) {
        try {
            $client = (isset($request->attributes) && is_object($request->attributes) && method_exists($request->attributes, 'get'))
                ? $request->attributes->get('api_client')
                : null;
            $applicationPassword = (isset($request->attributes) && is_object($request->attributes) && method_exists($request->attributes, 'get'))
                ? $request->attributes->get('application_password')
                : null;

            $clientData = null;
            if ($client) {
                $allowedOrigins = $client->allowed_origins ?? [];
                if (is_string($allowedOrigins)) {
                    $decoded = json_decode($allowedOrigins, true);
                    $allowedOrigins = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : explode(',', $allowedOrigins);
                }

                $permissions = $client->permissions ?? [];
                if (is_string($permissions)) {
                    $decoded = json_decode($permissions, true);
                    $permissions = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                }

                $clientData = [
                    'id' => $client->id ?? null,
                    'name' => $client->name ?? null,
                    'slug' => $client->slug ?? null,
                    'type' => $client->type ?? null,
                    'status' => method_exists($client, 'isActive')
                        ? $client->isActive()
                        : (bool) ($client->status ?? false),
                    'allowed_origins' => is_array($allowedOrigins) ? array_values(array_filter($allowedOrigins)) : [],
                    'permissions' => is_array($permissions) ? array_values($permissions) : [],
                    'requires_signature' => method_exists($client, 'isSignatureRequired')
                        ? $client->isSignatureRequired()
                        : (bool) ($client->requires_signature ?? false),
                ];
            }

            $appPasswordData = null;
            if ($applicationPassword) {
                $abilities = $applicationPassword->abilities ?? $applicationPassword->getAttribute('abilities') ?? [];
                if (is_string($abilities)) {
                    $decoded = json_decode($abilities, true);
                    $abilities = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                }

                $appPasswordData = [
                    'id' => $applicationPassword->id ?? null,
                    'name' => $applicationPassword->name ?? null,
                    'permissions' => is_array($abilities) ? array_values($abilities) : [],
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Application access verified successfully.',
                'data' => [
                    'api_client' => $clientData,
                    'application_password' => $appPasswordData,
                    'request' => [
                        'app_type' => $request->header('X-App-Type'),
                        'origin' => $request->header('Origin') ?: $request->header('X-App-Origin'),
                        'ip' => $request->ip(),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('app-access-check error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error checking application access.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
    /*
    |--------------------------------------------------------------------------
    | User Admin APIs
    |--------------------------------------------------------------------------
    | Admin user management routes.
    | Permission module: users
    |--------------------------------------------------------------------------
    */

    Route::middleware(['throttle:60,1', 'admin.token'])->group(function () {

        Route::post('/user/search', [UserController::class, 'SearchUser'])
            ->middleware('permission.check:users,read');

        Route::get('all-user-listing', [UserController::class, 'alluserlist'])
            ->middleware('permission.check:users,read');

        Route::get('/users/filter-by-role', [UserController::class, 'filterByRole'])
            ->middleware('permission.check:users,read');

        Route::get('/users/filter-by-status', [UserController::class, 'filterByStatus'])
            ->middleware('permission.check:users,read');

        Route::get('/get-all-users-by-role', [UserController::class, 'getDataUserDetailsByRole'])
            ->middleware('permission.check:users,read');

        Route::get('/get-userdata-by-id', [UserController::class, 'getDataUserDetailsById'])
            ->middleware('permission.check:users,read');

        Route::get('/user-analytics', [UserController::class, 'userAnalytics'])
            ->middleware('permission.check:users,read');

        Route::get('/get-user-status', [UserController::class, 'getUserStatusList'])
            ->middleware('permission.check:users,read');

        Route::post('create-user', [UserController::class, 'createUser'])
            ->middleware('permission.check:users,create');


        Route::post('update-user-byuserid', [UserController::class, 'updateuserbyid'])
            ->middleware('permission.check:users,edit');

        Route::post('update-user-status', [UserController::class, 'updateuserstatus'])
            ->middleware('permission.check:users,edit');

        Route::post('user-kyc-update', [KycController::class, 'updateKycStatus'])
            ->middleware('permission.check:users,edit');

        Route::get('check-user-deletion', [UserController::class, 'checkUserDeletion']);

        Route::post('delete-user', [UserController::class, 'deleteUser'])
            ->middleware('permission.check:users,delete');

        Route::post('user-bulk-delete', [UserController::class, 'bulkDelete'])
            ->middleware('permission.check:users,delete');
    });


    Route::middleware(['throttle:60,1', 'adminOrCurrentUser'])->group(function () {
        Route::get('get-details-byuserid', [UserController::class, 'getdetailsbyuserid']);
    });

    Route::middleware(['throttle:60,1', 'allrole.token'])->group(function () {
        Route::post('update-current-user-by-token', [UserController::class, 'updateCurrentUser']);
        Route::post('update-front-user-by-id', [UserController::class, 'updateUser']);
        Route::post('user-complete-kyc', [KycController::class, 'completeKyc']);
    });


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

    Route::prefix('admin')
        ->middleware(['admin.token'])
        ->group(function () {
            Route::get(
                '/get-admin-profile',
                [Admincontroller::class, 'getAdminProfile']
            )->middleware([
                        'throttle:60,1',
                        'token.expiration',
                    ]);

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

            Route::middleware(['throttle:60,1', 'permission.check:roles,create'])
                ->post('role-create', [RoleController::class, 'createRole']);

            Route::middleware(['throttle:60,1', 'permission.check:roles,edit'])
                ->post('role-edit', [RoleController::class, 'editRole']);

            Route::middleware(['throttle:60,1', 'permission.check:roles,delete'])
                ->post('role-delete', [RoleController::class, 'deleteRole']);

            Route::middleware(['throttle:60,1', 'permission.check:roles,read'])
                ->get('role-listing/{id?}', [RoleController::class, 'index']);

            Route::middleware(['throttle:60,1', 'permission.check:roles,delete'])
                ->post('roles/bulk-delete', [RoleController::class, 'bulkDeleteRoles']);

            Route::middleware(['throttle:60,1', 'permission.check:roles,read'])
                ->post('roles/search', [RoleController::class, 'searchRole']);
        });

    // ======= Analytics =========
    Route::middleware(['throttle:60,1', 'admin.token', 'permission.check:dashboard,read'])->get('admin-dashboard-analytics', [AdminDashboardAnalyticsController::class, 'adminDashboardAnalytics']);
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
    Route::middleware(['throttle:60,1', 'admin.token'])->match(['get', 'post'], 'groups-list', [GroupController::class, 'index']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('groups-delete/{id}', [GroupController::class, 'deleteGroup']);
    Route::get('/check-unique-group-name', [GroupController::class, 'checkUniqueGroupName'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('groups-bulk-delete', [GroupController::class, 'bulkDeleteGroups']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('groups-search', [GroupController::class, 'searchByGroupName']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/groups/import', [GroupController::class, 'importGroups']);
    Route::middleware(['throttle:60,1', 'admin.token'])->get('/groups/export', [GroupController::class, 'exportGroups']);



    // Group Route will end from here

    // Permission Route will start from here
    Route::middleware(['admin.token'])->group(function () {
        Route::middleware(['throttle:30,1', 'permission.check:permissions,create'])
            ->post('permissions/sync', [PermissionController::class, 'syncConfiguredPermissions']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,delete'])
            ->post('permissions-delete', [PermissionController::class, 'deletePermission']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,read'])
            ->get('permissions-listing', [PermissionController::class, 'index']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,edit'])
            ->post('permissions/assign', [PermissionController::class, 'assignPermission']);

        Route::middleware(['throttle:60,1', 'permission.check:users,edit'])
            ->post('role/assign', [Rolecontroller::class, 'assignRole']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,edit'])
            ->post('remove/permission', [PermissionController::class, 'removePermission']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,read'])
            ->get('/role/{roleId}/permissions', [PermissionController::class, 'getPermissionsByRole']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,edit'])
            ->post('assign-permissions', [PermissionController::class, 'assignDynamicPermissions']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,read'])
            ->get('/permissions/{role_id}', [PermissionController::class, 'getPermissionsByRole']);

        Route::middleware(['throttle:60,1', 'permission.check:permissions,read'])
            ->get('/model-names', [PermissionController::class, 'getModelNames']);
        Route::middleware(['throttle:60,1'])
            ->get('auth/permissions', [PermissionController::class, 'currentUserPermissions']);
    });
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
            Route::get('tickets-assignable-roles', [TicketController::class, 'assignableRoles']);
            Route::get('tickets-assignable-users', [TicketController::class, 'assignableUsers']);
            Route::get('tickets/roles', [TicketController::class, 'assignableRoles']);
            Route::get('tickets/assignable-users', [TicketController::class, 'assignableUsers']);
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


    // =========About us========

    Route::get('/about-us', [AboutUsController::class, 'show'])->middleware(['throttle:60,1']);
    Route::middleware(['throttle:60,1', 'admin.token'])->post('/about-us', [AboutUsController::class, 'storeOrUpdate']);


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
    Route::get('get-localities-filter-by-location-id', [LocationController::class, 'getAreaLocalities'])->middleware(['throttle:60,1']);
    Route::get('get-area-localities', [LocationController::class, 'getAreaLocalities'])->middleware(['throttle:60,1']);
    Route::get('get-locality', [LocationController::class, 'getAreaLocalities'])->middleware(['throttle:60,1']);
    Route::get('get-localities', [LocationController::class, 'getAreaLocalities'])->middleware(['throttle:60,1']);
    Route::get('locations/localities', [LocationController::class, 'getAreaLocalities'])->middleware(['throttle:60,1']);
    Route::get('locations/locality', [LocationController::class, 'getAreaLocalities'])->middleware(['throttle:60,1']);

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

    // Menu Management (WordPress Drag-and-Drop Navigation)
    Route::prefix('menus')->group(function () {
        Route::get('/', [MenuController::class, 'index'])->middleware(['throttle:60,1']);
        Route::get('/locations', [MenuController::class, 'locations'])->middleware(['throttle:60,1']);
        Route::get('/sources', [MenuController::class, 'sources'])->middleware(['throttle:60,1']);
        Route::get('/show/{id}', [MenuController::class, 'show'])->middleware(['throttle:60,1']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/store', [MenuController::class, 'store']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/add-items', [MenuController::class, 'addItems']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/save-tree', [MenuController::class, 'saveTree']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/reorder', [MenuController::class, 'reorder']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/update/{id}', [MenuController::class, 'update']);
        Route::middleware(['throttle:60,1', 'admin.token'])->delete('/delete/{id}', [MenuController::class, 'destroy']);
        Route::middleware(['throttle:60,1', 'admin.token'])->post('/bulk-delete', [MenuController::class, 'bulkDestroy']);
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
        Route::middleware('permission.check:api_clients,read')
            ->get('api-clients/available-permissions', [ApiClientController::class, 'availablePermissions']);

        Route::middleware('permission.check:api_clients,read')
            ->get('api-clients', [ApiClientController::class, 'index']);

        Route::middleware('permission.check:api_clients,create')
            ->post('api-clients', [ApiClientController::class, 'store']);

        Route::middleware('permission.check:api_clients,read')
            ->get('api-clients/{apiClient}', [ApiClientController::class, 'show']);

        Route::middleware('permission.check:api_clients,edit')
            ->match(['put', 'patch'], 'api-clients/{apiClient}', [ApiClientController::class, 'update']);

        Route::middleware('permission.check:api_clients,delete')
            ->delete('api-clients/{apiClient}', [ApiClientController::class, 'destroy']);

        Route::middleware('permission.check:application_passwords,read')
            ->get('api-clients/{apiClient}/application-passwords', [ApplicationPasswordController::class, 'index']);

        Route::middleware('permission.check:application_passwords,create')
            ->post('api-clients/{apiClient}/application-passwords', [ApplicationPasswordController::class, 'store']);

        Route::middleware('permission.check:application_passwords,delete')
            ->delete('api-clients/{apiClient}/application-passwords/{applicationPassword}', [ApplicationPasswordController::class, 'destroy']);

        Route::middleware('permission.check:application_passwords,edit')
            ->post('api-clients/{apiClient}/application-passwords/{applicationPassword}/rotate', [ApplicationPasswordController::class, 'rotate']);

        Route::middleware('permission.check:blocked_api_ips,read')
            ->get('blocked-api-ips', [BlockedApiIpController::class, 'index']);

        Route::middleware('permission.check:blocked_api_ips,create')
            ->post('blocked-api-ips', [BlockedApiIpController::class, 'store']);

        Route::middleware('permission.check:blocked_api_ips,delete')
            ->delete('blocked-api-ips/{blockedApiIp}', [BlockedApiIpController::class, 'destroy']);

        Route::middleware('permission.check:api_auth_failures,read')
            ->get('api-auth-failures', [ApiAuthFailureController::class, 'index']);

        Route::middleware('permission.check:api_auth_failures,read')
            ->get('api-auth-failures/reasons', [ApiAuthFailureController::class, 'reasons']);

        Route::middleware('permission.check:api_auth_failures,read')
            ->get('api-auth-failures/top-ips', [ApiAuthFailureController::class, 'topIps']);
    });

// IpLog

Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs', [IpLogController::class, 'index']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('admin/ip-logs-update-status', [IpLogController::class, 'updateIpStatus']);

Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs-by-ip-address', [IpLogController::class, 'getByIpAddress']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs-by-user-id', [IpLogController::class, 'getByUserId']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/user-login-history', [IpLogController::class, 'getByUserId']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/login-history', [IpLogController::class, 'index']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/users/{userId}/login-history', [IpLogController::class, 'getByUserId'])->whereNumber('userId');
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/ip-logs-by-id', [IpLogController::class, 'getById']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('admin/ip-logs-update-status-by-ip', [IpLogController::class, 'updateStatusByIp']);

// Admin User Notifications
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/user-notifications', [AdminUserNotificationsController::class, 'index']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/users/{userId}/notifications', [AdminUserNotificationsController::class, 'index'])->whereNumber('userId');
Route::middleware(['throttle:60,1', 'admin.token'])->post('admin/user-notifications', [AdminUserNotificationsController::class, 'store']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('admin/user-notifications/{notification}/read', [AdminUserNotificationsController::class, 'markAsRead']);
Route::middleware(['throttle:60,1', 'admin.token'])->delete('admin/user-notifications/{notification}', [AdminUserNotificationsController::class, 'destroy']);

// Admin User Activity Logs
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/user-activities', [UserActivityLogController::class, 'index']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/user-activity-log', [UserActivityLogController::class, 'index']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/users/{userId}/activities', [UserActivityLogController::class, 'index'])->whereNumber('userId');
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/users/{userId}/activity-log', [UserActivityLogController::class, 'index'])->whereNumber('userId');
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/user-activities/{activity}', [UserActivityLogController::class, 'show']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('admin/user-activities', [UserActivityLogController::class, 'store']);
Route::middleware(['throttle:60,1', 'admin.token'])->delete('admin/user-activities/{activity}', [UserActivityLogController::class, 'destroy']);

// Role-Based User Listings & Tabs (Property Listing, Project Listing, etc.)
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/users/{userId}/listings', [AdminUserListingController::class, 'index'])->whereNumber('userId');
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/users/{userId}/listing-tabs', [AdminUserListingController::class, 'allowedTabs'])->whereNumber('userId');
Route::middleware(['throttle:60,1', 'admin.token'])->get('admin/get-listing-by-userid', [AdminUserListingController::class, 'index']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('get-listing-by-userid', [AdminUserListingController::class, 'index']);


Route::middleware(['throttle:60,1', 'admin.token'])->get('/business-enquiries', [BusinessEnquiryController::class, 'index']);
Route::post('/business-enquiries', [BusinessEnquiryController::class, 'store'])->middleware(['throttle:60,1']);
Route::middleware(['throttle:60,1', 'admin.token'])->get('/business-enquiries/{id}', [BusinessEnquiryController::class, 'show']);
Route::middleware(['throttle:60,1', 'admin.token'])->delete('/business-enquiries/{id}', [BusinessEnquiryController::class, 'destroy']);
Route::middleware(['throttle:60,1', 'admin.token'])->post('/business-enquiries/bulk-delete', [BusinessEnquiryController::class, 'bulkDelete']);
Route::prefix('admin')
    ->middleware([
        'validate.api.client',
        'admin.token',
    ])
    ->group(function () {
        Route::patch(
            'property-listings/{property}/availability',
            [
                PropertyAvailabilityController::class,
                'adminUpdate',
            ]
        )->middleware([
                    'permission.check:property_availability,update',
                    'throttle:property-availability-admin',
                ]);

        Route::get(
            'property-listings/{property}/availability-history',
            [
                PropertyAvailabilityController::class,
                'adminHistory',
            ]
        )->middleware([
                    'permission.check:property_availability,history',
                    'throttle:property-availability-admin',
                ]);
    });
Route::middleware(['admin.token', 'throttle:kyc-admin'])
    ->prefix('admin/kyc')
    ->group(function () {
        Route::middleware(['permission.check:kyc_requests,read'])
            ->get('stats', [AdminKycController::class, 'stats']);

        Route::middleware(['permission.check:kyc_requests,assign'])
            ->get('verifier-roles', [AdminKycController::class, 'verifierRoles']);

        Route::middleware(['permission.check:kyc_requests,assign'])
            ->get('verifiers', [AdminKycController::class, 'verifiers']);

        Route::middleware(['permission.check:kyc_requests,read'])
            ->get('my-assigned', [AdminKycController::class, 'myAssigned']);

        Route::middleware(['permission.check:kyc_requests,read'])
            ->get('requests', [AdminKycController::class, 'index']);

        Route::middleware(['permission.check:kyc_requests,assign'])
            ->post('requests/bulk-assign', [AdminKycController::class, 'bulkAssign']);

        Route::middleware(['permission.check:kyc_requests,assign'])
            ->post('requests/assign-all', [AdminKycController::class, 'assignAllOpen']);

        Route::middleware(['permission.check:kyc_requests,read'])
            ->get('requests/{kycRequest}', [AdminKycController::class, 'show'])
            ->whereNumber('kycRequest')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC request not found.'], 404));

        Route::middleware(['permission.check:kyc_requests,assign'])
            ->post('requests/{kycRequest}/assign', [AdminKycController::class, 'assign'])
            ->whereNumber('kycRequest')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC request not found.'], 404));

        Route::middleware(['permission.check:kyc_requests,read'])
            ->get('requests/{kycRequest}/documents', [AdminKycController::class, 'documents'])
            ->whereNumber('kycRequest')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC request not found.'], 404));

        Route::middleware(['permission.check:kyc_requests,read'])
            ->get('requests/{kycRequest}/timeline', [AdminKycController::class, 'timeline'])
            ->whereNumber('kycRequest')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC request not found.'], 404));

        Route::middleware(['permission.check:kyc_requests,edit'])
            ->post('requests/{kycRequest}/start-review', [AdminKycController::class, 'startReview'])
            ->whereNumber('kycRequest')
            ->defaults('review_action', 'start_review')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC request not found.'], 404));

        Route::middleware(['permission.check:kyc_requests,approve'])
            ->post('requests/{kycRequest}/approve', [AdminKycController::class, 'approve'])
            ->whereNumber('kycRequest')
            ->defaults('review_action', 'approve')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC request not found.'], 404));

        Route::middleware(['permission.check:kyc_requests,reject'])
            ->post('requests/{kycRequest}/reject', [AdminKycController::class, 'reject'])
            ->whereNumber('kycRequest')
            ->defaults('review_action', 'reject')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC request not found.'], 404));

        Route::middleware(['permission.check:kyc_requests,read'])
            ->get('documents/{document}/view', [AdminKycController::class, 'viewDocument'])
            ->whereNumber('document')
            ->missing(fn() => response()->json(['status' => false, 'message' => 'KYC document not found.'], 404));
    });

Route::middleware(['admin.token', 'throttle:kyc-admin'])
    ->prefix('kyc/settings')
    ->group(function () {
        Route::middleware(['permission.check:kyc_settings,read'])
            ->get('roles', [KycSettingsController::class, 'availableRoles']);

        Route::middleware(['permission.check:kyc_settings,read'])
            ->get('role-rules', [KycSettingsController::class, 'roleRules']);

        Route::middleware(['permission.check:kyc_settings,edit'])
            ->post('role-rules', [KycSettingsController::class, 'updateRoleRule']);

        Route::middleware(['permission.check:kyc_settings,read'])
            ->get('user-exemptions', [KycSettingsController::class, 'userExemptions']);

        Route::middleware(['permission.check:kyc_settings,edit'])
            ->post('user-exemptions', [KycSettingsController::class, 'createUserExemption']);

        Route::middleware(['permission.check:kyc_settings,edit'])
            ->post('user-exemptions/{exemption}/revoke', [KycSettingsController::class, 'revokeUserExemption'])
            ->whereNumber('exemption');

        Route::middleware(['permission.check:kyc_settings,read'])
            ->get('users/{userId}/access-status', [KycSettingsController::class, 'userAccessStatus'])
            ->whereNumber('userId');
    });
Route::middleware([
    'validate.api.client',
    'admin.token',
    'throttle:60,1',
])
    ->prefix('admin/property-verifications')
    ->group(function () {
        Route::get(
            '/',
            [PropertyVerificationController::class, 'index']
        )->middleware(
                'permission.check:property_verifications,read'
            );

        // Static route must be declared before /{property}.
        Route::get(
            '/verifiers',
            [PropertyVerificationController::class, 'verifiers']
        )->middleware(
                'permission.check:property_verifications,assign'
            );

        Route::get(
            '/verifier-roles',
            [PropertyVerificationController::class, 'verifierRoles']
        )->middleware(
                'permission.check:property_verifications,assign'
            );

        Route::get(
            '/my-assigned',
            [PropertyVerificationController::class, 'myAssigned']
        )->middleware(
                'permission.check:property_verifications,read'
            );

        Route::post(
            '/bulk-assign',
            [PropertyVerificationController::class, 'bulkAssign']
        )->middleware(
                'permission.check:property_verifications,assign'
            );

        Route::post(
            '/assign-all-open',
            [PropertyVerificationController::class, 'assignAllOpen']
        )->middleware(
                'permission.check:property_verifications,assign'
            );

        Route::get(
            '/{property}',
            [PropertyVerificationController::class, 'show']
        )
            ->middleware(
                'permission.check:property_verifications,read'
            )
            ->whereNumber('property');

        Route::post(
            '/{property}/assign',
            [PropertyVerificationController::class, 'assign']
        )
            ->middleware(
                'permission.check:property_verifications,assign'
            )
            ->whereNumber('property');

        Route::post(
            '/{property}/start',
            [PropertyVerificationController::class, 'startVerification']
        )
            ->middleware(
                'permission.check:property_verifications,review'
            )
            ->whereNumber('property');

        Route::post(
            '/{property}/approve',
            [PropertyVerificationController::class, 'approve']
        )
            ->middleware(
                'permission.check:property_verifications,approve'
            )
            ->whereNumber('property');

        Route::post(
            '/{property}/reject',
            [PropertyVerificationController::class, 'reject']
        )
            ->middleware(
                'permission.check:property_verifications,reject'
            )
            ->whereNumber('property');

        Route::get(
            '/{property}/timeline',
            [PropertyVerificationController::class, 'timeline']
        )
            ->middleware(
                'permission.check:property_verifications,read'
            )
            ->whereNumber('property');
    });
Route::prefix('admin/payment-gateways')
    ->middleware([
        'validate.api.client',
        'admin.token',
        'throttle:membership-admin',
    ])
    ->group(function () {
        Route::get('razorpay', [PaymentGatewayController::class, 'razorpay'])
            ->middleware('permission.check:payment_gateways,read');

        Route::put('razorpay', [PaymentGatewayController::class, 'updateRazorpay'])
            ->middleware('permission.check:payment_gateways,edit');

        Route::patch('razorpay', [PaymentGatewayController::class, 'updateRazorpay'])
            ->middleware('permission.check:payment_gateways,edit');
    });

/*
|--------------------------------------------------------------------------
| Admin Firebase Notification Config
|--------------------------------------------------------------------------
*/
Route::prefix('admin/notification-config')
    ->middleware([
        'validate.api.client',
        'admin.token',
        'throttle:notification-admin',
    ])
    ->group(function () {

        Route::get(
            'firebase',
            [NotificationConfigController::class, 'firebase']
        )->middleware(
                'permission.check:notification_config,read'
            );

        Route::put(
            'firebase',
            [NotificationConfigController::class, 'updateFirebase']
        )->middleware(
                'permission.check:notification_config,edit'
            );

        Route::patch(
            'firebase',
            [NotificationConfigController::class, 'updateFirebase']
        )->middleware(
                'permission.check:notification_config,edit'
            );

        Route::post(
            'firebase/test-token',
            [NotificationConfigController::class, 'testToken']
        )->middleware(
                'permission.check:notification_config,read'
            );

        Route::post(
            'firebase/test-send',
            [NotificationConfigController::class, 'testSend']
        )->middleware(
                'permission.check:notification_config,read'
            );
    });


/*
|--------------------------------------------------------------------------
| Admin Notifications
|--------------------------------------------------------------------------
*/
Route::prefix('admin/notifications')
    ->middleware([
        'validate.api.client',
        'admin.token',
        'throttle:notification-admin',
    ])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Send Notification
        |--------------------------------------------------------------------------
        */
        Route::post(
            'send',
            [AdminNotificationSendController::class, 'send']
        )->middleware(
                'permission.check:notifications,send'
            );


        /*
        |--------------------------------------------------------------------------
        | Admin Personal Inbox
        |--------------------------------------------------------------------------
        */

        Route::get(
            'inbox',
            [AdminNotificationInboxController::class, 'index']
        );

        Route::get(
            'inbox/unread-count',
            [AdminNotificationInboxController::class, 'unreadCount']
        );

        Route::post(
            'inbox/read-all',
            [AdminNotificationInboxController::class, 'markAllAsRead']
        );

        Route::get(
            'inbox/{notification}',
            [AdminNotificationInboxController::class, 'show']
        );

        Route::post(
            'inbox/{notification}/read',
            [AdminNotificationInboxController::class, 'markAsRead']
        );


        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get(
            'dashboard',
            [AdminNotificationReportController::class, 'dashboard']
        );


        /*
        |--------------------------------------------------------------------------
        | In-App Notification Reports
        |--------------------------------------------------------------------------
        */

        Route::get(
            'in-app',
            [AdminNotificationReportController::class, 'inAppNotifications']
        );

        Route::delete(
            'in-app/clear-all',
            [AdminNotificationReportController::class, 'clearAllInAppNotifications']
        )->middleware(
                'permission.check:notification_reports,delete'
            );


        /*
        |--------------------------------------------------------------------------
        | Devices
        |--------------------------------------------------------------------------
        */

        Route::get(
            'devices',
            [AdminNotificationDeviceController::class, 'index']
        );


        /*
        |--------------------------------------------------------------------------
        | Batches
        |--------------------------------------------------------------------------
        */

        Route::get(
            'batches',
            [AdminNotificationReportController::class, 'batches']
        );

        Route::delete(
            'batches/clear-all',
            [AdminNotificationReportController::class, 'clearAllBatches']
        )->middleware(
                'permission.check:notification_reports,delete'
            );

        Route::post(
            'batches/{batch}/retry-failed',
            [AdminNotificationRetryController::class, 'retryBatchFailed']
        )
            ->middleware(
                'permission.check:notifications,retry'
            )
            ->whereNumber('batch');

        Route::get(
            'batches/{batch}/logs',
            [AdminNotificationReportController::class, 'batchLogs']
        )
            ->middleware(
                'permission.check:notification_reports,read'
            )
            ->whereNumber('batch');

        Route::get(
            'batches/{batch}',
            [AdminNotificationReportController::class, 'showBatch']
        )
            ->middleware(
                'permission.check:notification_reports,read'
            )
            ->whereNumber('batch');


        /*
        |--------------------------------------------------------------------------
        | Logs
        |--------------------------------------------------------------------------
        */

        Route::get(
            'logs',
            [AdminNotificationReportController::class, 'logs']
        );

        Route::delete(
            'logs/clear-all',
            [AdminNotificationReportController::class, 'clearLogs']
        )->middleware(
                'permission.check:notification_reports,delete'
            );

        Route::post(
            'logs/retry-failed',
            [AdminNotificationRetryController::class, 'retryLogsFailed']
        )->middleware(
                'permission.check:notifications,retry'
            );


        /*
        |--------------------------------------------------------------------------
        | Notification Topics
        |--------------------------------------------------------------------------
        */

        Route::get(
            'topics',
            [AdminNotificationTopicController::class, 'index']
        )->middleware(
                'permission.check:notification_topics,read'
            );

        Route::post(
            'topics',
            [AdminNotificationTopicController::class, 'store']
        )->middleware(
                'permission.check:notification_topics,create'
            );

        Route::get(
            'topics/{topic}/subscribers',
            [AdminNotificationTopicController::class, 'subscribers']
        )
            ->middleware(
                'permission.check:notification_topics,read'
            )
            ->whereNumber('topic');

        Route::get(
            'topics/{topic}',
            [AdminNotificationTopicController::class, 'show']
        )
            ->middleware(
                'permission.check:notification_topics,read'
            )
            ->whereNumber('topic');

        Route::put(
            'topics/{topic}',
            [AdminNotificationTopicController::class, 'update']
        )
            ->middleware(
                'permission.check:notification_topics,edit'
            )
            ->whereNumber('topic');

        Route::patch(
            'topics/{topic}',
            [AdminNotificationTopicController::class, 'update']
        )
            ->middleware(
                'permission.check:notification_topics,edit'
            )
            ->whereNumber('topic');

        Route::delete(
            'topics/{topic}',
            [AdminNotificationTopicController::class, 'destroy']
        )
            ->middleware(
                'permission.check:notification_topics,delete'
            )
            ->whereNumber('topic');


        /*
        |--------------------------------------------------------------------------
        | Notification Templates
        |--------------------------------------------------------------------------
        */

        Route::get(
            'templates/options',
            [AdminNotificationTemplateController::class, 'options']
        )->middleware(
                'permission.check:notification_templates,read'
            );

        Route::get(
            'templates',
            [AdminNotificationTemplateController::class, 'index']
        )->middleware(
                'permission.check:notification_templates,read'
            );

        Route::post(
            'templates',
            [AdminNotificationTemplateController::class, 'store']
        )->middleware(
                'permission.check:notification_templates,create'
            );

        Route::get(
            'templates/{template}',
            [AdminNotificationTemplateController::class, 'show']
        )
            ->middleware(
                'permission.check:notification_templates,read'
            )
            ->whereNumber('template');

        Route::put(
            'templates/{template}',
            [AdminNotificationTemplateController::class, 'update']
        )
            ->middleware(
                'permission.check:notification_templates,edit'
            )
            ->whereNumber('template');

        Route::patch(
            'templates/{template}',
            [AdminNotificationTemplateController::class, 'update']
        )
            ->middleware(
                'permission.check:notification_templates,edit'
            )
            ->whereNumber('template');

        Route::delete(
            'templates/{template}',
            [AdminNotificationTemplateController::class, 'destroy']
        )
            ->middleware(
                'permission.check:notification_templates,delete'
            )
            ->whereNumber('template');


        /*
        |--------------------------------------------------------------------------
        | Payload Options
        |--------------------------------------------------------------------------
        */

        Route::get(
            'payload-options',
            [NotificationPayloadOptionController::class, 'index']
        )->middleware(
                'permission.check:notifications,read'
            );
    });


/*
|--------------------------------------------------------------------------
| Admin Membership Settings
|--------------------------------------------------------------------------
|
| IMPORTANT:
| This block is separate from admin/notifications.
|
*/
Route::prefix('admin/membership/settings')
    ->middleware([
        'validate.api.client',
        'admin.token',
        'throttle:membership-admin',
    ])
    ->group(function () {

        Route::get(
            'tax',
            [AdminMembershipTaxSettingController::class, 'show']
        )->middleware(
                'permission.check:membership_settings,read'
            );

        Route::put(
            'tax',
            [AdminMembershipTaxSettingController::class, 'update']
        )->middleware(
                'permission.check:membership_settings,edit'
            );

        Route::patch(
            'tax',
            [AdminMembershipTaxSettingController::class, 'update']
        )->middleware(
                'permission.check:membership_settings,edit'
            );

        Route::post(
            'tax/preview',
            [AdminMembershipTaxSettingController::class, 'calculatePreview']
        )->middleware(
                'permission.check:membership_settings,read'
            );
    });

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
    Route::prefix('admin/membership')
        ->middleware('throttle:membership-admin')
        ->group(function () {

            /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */
            Route::get('categories/export', [AdminMembershipCatalogController::class, 'exportCategories'])
                ->middleware('permission.check:membership_categories,read');

            Route::post('categories/import', [AdminMembershipCatalogController::class, 'importCategories'])
                ->middleware('permission.check:membership_categories,create');

            Route::post('categories/bulk-delete', [AdminMembershipCatalogController::class, 'bulkDeleteCategories'])
                ->middleware('permission.check:membership_categories,delete');

            Route::get('categories', [AdminMembershipCatalogController::class, 'categories'])
                ->middleware('permission.check:membership_categories,read');

            Route::post('categories', [AdminMembershipCatalogController::class, 'storeCategory'])
                ->middleware('permission.check:membership_categories,create');

            Route::put('categories/{category}', [AdminMembershipCatalogController::class, 'updateCategory'])
                ->middleware('permission.check:membership_categories,edit');

            Route::patch('categories/{category}', [AdminMembershipCatalogController::class, 'updateCategory'])
                ->middleware('permission.check:membership_categories,edit');

            Route::delete('categories/{category}', [AdminMembershipCatalogController::class, 'deleteCategory'])
                ->middleware('permission.check:membership_categories,delete');


            /*
        |--------------------------------------------------------------------------
        | Features
        |--------------------------------------------------------------------------
        */
            Route::get('features/export', [AdminMembershipCatalogController::class, 'exportFeatures'])
                ->middleware('permission.check:membership_features,read');

            Route::post('features/import', [AdminMembershipCatalogController::class, 'importFeatures'])
                ->middleware('permission.check:membership_features,create');

            Route::post('features/bulk-delete', [AdminMembershipCatalogController::class, 'bulkDeleteFeatures'])
                ->middleware('permission.check:membership_features,delete');

            Route::get('features', [AdminMembershipCatalogController::class, 'features'])
                ->middleware('permission.check:membership_features,read');

            Route::post('features', [AdminMembershipCatalogController::class, 'storeFeature'])
                ->middleware('permission.check:membership_features,create');

            Route::get('features/{feature}', [AdminMembershipCatalogController::class, 'showFeature'])
                ->middleware('permission.check:membership_features,read');

            Route::put('features/{feature}', [AdminMembershipCatalogController::class, 'updateFeature'])
                ->middleware('permission.check:membership_features,edit');

            Route::patch('features/{feature}', [AdminMembershipCatalogController::class, 'updateFeature'])
                ->middleware('permission.check:membership_features,edit');

            Route::delete('features/{feature}', [AdminMembershipCatalogController::class, 'deleteFeature'])
                ->middleware('permission.check:membership_features,delete');


            /*
        |--------------------------------------------------------------------------
        | Plans
        |--------------------------------------------------------------------------
        */
            Route::get('plans/export', [AdminMembershipCatalogController::class, 'exportPlans'])
                ->middleware('permission.check:membership_plans,read');

            Route::post('plans/import', [AdminMembershipCatalogController::class, 'importPlans'])
                ->middleware('permission.check:membership_plans,create');

            Route::post('plans/bulk-delete', [AdminMembershipCatalogController::class, 'bulkDeletePlans'])
                ->middleware('permission.check:membership_plans,delete');

            Route::get('plans', [AdminMembershipCatalogController::class, 'plans'])
                ->middleware('permission.check:membership_plans,read');

            Route::post('plans', [AdminMembershipCatalogController::class, 'storePlan'])
                ->middleware('permission.check:membership_plans,create');

            Route::get('plans/{plan}', [AdminMembershipCatalogController::class, 'showPlan'])
                ->middleware('permission.check:membership_plans,read');

            Route::put('plans/{plan}', [AdminMembershipCatalogController::class, 'updatePlan'])
                ->middleware('permission.check:membership_plans,edit');

            Route::patch('plans/{plan}', [AdminMembershipCatalogController::class, 'updatePlan'])
                ->middleware('permission.check:membership_plans,edit');

            Route::delete('plans/{plan}', [AdminMembershipCatalogController::class, 'deletePlan'])
                ->middleware('permission.check:membership_plans,delete');

            Route::post('plans/{plan}/features', [AdminMembershipCatalogController::class, 'syncPlanFeatures'])
                ->middleware('permission.check:membership_plan_rules,edit');

            Route::post('plans/{plan}/roles', [AdminMembershipCatalogController::class, 'syncPlanRoles'])
                ->middleware('permission.check:membership_plan_rules,edit');

            Route::post('plans/{plan}/role-rules', [AdminMembershipCatalogController::class, 'syncPlanRoles'])
                ->middleware('permission.check:membership_plan_rules,edit');

            Route::get('plans/{plan}/features', [AdminMembershipCatalogController::class, 'planFeatures'])
                ->whereNumber('plan')
                ->middleware('permission.check:membership_plans,read');

            /*
        |--------------------------------------------------------------------------
        | Orders / Payments / Users / Credits
        |--------------------------------------------------------------------------
        */
            Route::get('orders', [AdminMembershipUserController::class, 'orders'])
                ->middleware('permission.check:membership_orders,read');

            Route::get('orders/{order}', [AdminMembershipUserController::class, 'showOrder'])
                ->middleware('permission.check:membership_orders,read');

            Route::get('payments', [AdminMembershipUserController::class, 'payments'])
                ->middleware('permission.check:membership_payments,read');

            Route::get('users', [AdminMembershipUserController::class, 'memberships'])
                ->middleware('permission.check:membership_users,read');

            Route::post('orders/{order}/cancel', [AdminMembershipUserController::class, 'cancelOrder'])
                ->middleware('permission.check:membership_orders,edit');

            Route::get('payments/{payment}', [AdminMembershipUserController::class, 'showPayment'])
                ->middleware('permission.check:membership_payments,read');

            Route::post('users/{user}/manual-activate', [AdminMembershipUserController::class, 'manualActivate'])
                ->middleware('permission.check:membership_users,manual_activate');

            Route::get('memberships', [AdminMembershipUserController::class, 'memberships'])
                ->middleware('permission.check:membership_users,read');

            Route::get('memberships/{membership}', [AdminMembershipUserController::class, 'showMembership'])
                ->middleware('permission.check:membership_users,read');

            Route::post('memberships/{membership}/cancel', [AdminMembershipUserController::class, 'cancelMembership'])
                ->middleware('permission.check:membership_users,cancel');

            Route::post('memberships/{membership}/expire', [AdminMembershipUserController::class, 'expireMembership'])
                ->middleware('permission.check:membership_users,edit');

            Route::get('credit-transactions', [AdminMembershipUserController::class, 'creditTransactions'])
                ->middleware('permission.check:membership_credits,read');

            Route::get('credits', [AdminMembershipUserController::class, 'userCredits'])
                ->middleware('permission.check:membership_credits,read');

            Route::post('credits/adjust', [AdminMembershipUserController::class, 'adjustCredit'])
                ->middleware('permission.check:membership_credits,adjust');


            /*
        |--------------------------------------------------------------------------
        | Coupons
        |--------------------------------------------------------------------------
        */
            Route::get('coupons', [AdminMembershipCouponController::class, 'index'])
                ->middleware('permission.check:membership_coupons,read');

            Route::post('coupons', [AdminMembershipCouponController::class, 'store'])
                ->middleware('permission.check:membership_coupons,create');

            Route::get('coupons/{coupon}', [AdminMembershipCouponController::class, 'show'])
                ->middleware('permission.check:membership_coupons,read');

            Route::put('coupons/{coupon}', [AdminMembershipCouponController::class, 'update'])
                ->middleware('permission.check:membership_coupons,edit');

            Route::patch('coupons/{coupon}', [AdminMembershipCouponController::class, 'update'])
                ->middleware('permission.check:membership_coupons,edit');

            Route::delete('coupons/{coupon}', [AdminMembershipCouponController::class, 'destroy'])
                ->middleware('permission.check:membership_coupons,delete');


            /*
        |--------------------------------------------------------------------------
        | Add-ons / Add-on Orders
        |--------------------------------------------------------------------------
        */
            Route::get('addon-orders', [AdminMembershipAddonOrderController::class, 'index'])
                ->middleware('permission.check:membership_addons,read');

            Route::get('addon-orders/{addonOrder}', [AdminMembershipAddonOrderController::class, 'show'])
                ->middleware('permission.check:membership_addons,read');

            Route::post('addon-orders/{addonOrder}/mark-failed', [AdminMembershipAddonOrderController::class, 'markFailed'])
                ->middleware('permission.check:membership_addons,edit');

            Route::post('addon-orders/{addonOrder}/apply-benefits', [AdminMembershipAddonOrderController::class, 'applyBenefits'])
                ->middleware('permission.check:membership_addons,edit');

            Route::get('addon-usages', [AdminMembershipAddonOrderController::class, 'usages'])
                ->middleware('permission.check:membership_addons,read');

            Route::get('addons', [AdminMembershipAddonController::class, 'index'])
                ->middleware('permission.check:membership_addons,read');

            Route::post('addons', [AdminMembershipAddonController::class, 'store'])
                ->middleware('permission.check:membership_addons,create');

            Route::get('addons/{addon}', [AdminMembershipAddonController::class, 'show'])
                ->middleware('permission.check:membership_addons,read');

            Route::put('addons/{addon}', [AdminMembershipAddonController::class, 'update'])
                ->middleware('permission.check:membership_addons,edit');

            Route::patch('addons/{addon}', [AdminMembershipAddonController::class, 'update'])
                ->middleware('permission.check:membership_addons,edit');

            Route::delete('addons/{addon}', [AdminMembershipAddonController::class, 'destroy'])
                ->middleware('permission.check:membership_addons,delete');


            /*
        |--------------------------------------------------------------------------
        | Invoices
        |--------------------------------------------------------------------------
        */
            Route::get('invoices', [AdminMembershipInvoiceController::class, 'index'])
                ->middleware('permission.check:membership_invoices,read');

            Route::get('invoices/{invoice}/download', [AdminMembershipInvoiceController::class, 'download'])
                ->middleware('permission.check:membership_invoices,download');

            Route::get('invoices/{invoice}', [AdminMembershipInvoiceController::class, 'show'])
                ->middleware('permission.check:membership_invoices,read');


            /*
        |--------------------------------------------------------------------------
        | Settings
        |--------------------------------------------------------------------------
        */
            Route::get('settings', [AdminMembershipSettingController::class, 'index'])
                ->middleware('permission.check:membership_settings,read');

            Route::post('settings', [AdminMembershipSettingController::class, 'store'])
                ->middleware('permission.check:membership_settings,create');

            Route::get('settings/{setting}', [AdminMembershipSettingController::class, 'show'])
                ->middleware('permission.check:membership_settings,read');

            Route::put('settings/{setting}', [AdminMembershipSettingController::class, 'update'])
                ->middleware('permission.check:membership_settings,edit');

            Route::patch('settings/{setting}', [AdminMembershipSettingController::class, 'update'])
                ->middleware('permission.check:membership_settings,edit');

            Route::delete('settings/{setting}', [AdminMembershipSettingController::class, 'destroy'])
                ->middleware('permission.check:membership_settings,delete');


            /*
        |--------------------------------------------------------------------------
        | Reports
        |--------------------------------------------------------------------------
        */
            Route::get('reports/dashboard', [AdminMembershipReportController::class, 'dashboard'])
                ->middleware('permission.check:membership_reports,read');

            Route::get('reports/revenue', [AdminMembershipReportController::class, 'revenue'])
                ->middleware('permission.check:membership_reports,read');

            Route::get('reports/credits', [AdminMembershipReportController::class, 'credits'])
                ->middleware('permission.check:membership_reports,read');

            Route::get('reports/top-plans', [AdminMembershipReportController::class, 'topPlans'])
                ->middleware('permission.check:membership_reports,read');

            Route::get('reports/top-addons', [AdminMembershipReportController::class, 'topAddons'])
                ->middleware('permission.check:membership_reports,read');


            /*
        |--------------------------------------------------------------------------
        | Refunds
        |--------------------------------------------------------------------------
        */
            Route::get('refunds', [AdminMembershipRefundController::class, 'index'])
                ->middleware('permission.check:membership_payments,read');

            Route::post('refunds', [AdminMembershipRefundController::class, 'store'])
                ->middleware('permission.check:membership_payments,refund');

            Route::post('refunds/{refund}/mark-processed', [AdminMembershipRefundController::class, 'markProcessed'])
                ->middleware('permission.check:membership_payments,refund');

            Route::post('refunds/{refund}/mark-failed', [AdminMembershipRefundController::class, 'markFailed'])
                ->middleware('permission.check:membership_payments,refund');

            Route::get('refunds/{refund}', [AdminMembershipRefundController::class, 'show'])
                ->middleware('permission.check:membership_payments,read');


            /*
        |--------------------------------------------------------------------------
        | Audit Logs
        |--------------------------------------------------------------------------
        */
            Route::get('audit-logs', [AdminMembershipAuditLogController::class, 'index'])
                ->middleware('permission.check:membership_reports,read');

            Route::get('audit-logs/{auditLog}', [AdminMembershipAuditLogController::class, 'show'])
                ->middleware('permission.check:membership_reports,read');
        });
});
// ================= VK Admin CRM Builder APIs =================
Route::middleware(['throttle:60,1', 'admin.token', 'validate.api.client'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    */

    // Template dropdowns and helpers
    Route::get('template-dynamic-fields', [TemplateDynamicFieldController::class, 'index']);
    Route::get('template-options', [TemplateController::class, 'options']);
    Route::get('template-shortcodes', [TemplateController::class, 'shortcodes']);

    // Template CRUD
    Route::get('templates-list', [TemplateController::class, 'index']);
    Route::post('templates-create', [TemplateController::class, 'create']);
    Route::get('templates-show/{id}', [TemplateController::class, 'show'])->whereNumber('id');
    Route::post('templates-update/{id}', [TemplateController::class, 'update'])->whereNumber('id');
    Route::post('templates-update-status/{id}', [TemplateController::class, 'updateStatus'])->whereNumber('id');
    Route::delete('templates-delete/{id}', [TemplateController::class, 'destroy'])->whereNumber('id');

    // Template import and export
    Route::get('template-export/{id}', [TemplateExportImportController::class, 'export'])->whereNumber('id');
    Route::post('template-import', [TemplateExportImportController::class, 'import']);

    // Template revisions
    Route::get('template-revisions/{template_id}', [TemplateRevisionController::class, 'index'])
        ->whereNumber('template_id');

    Route::get('template-revisions/{template_id}/{revision_id}', [TemplateRevisionController::class, 'show'])
        ->whereNumber('template_id')
        ->whereNumber('revision_id');

    Route::post('template-revisions/{template_id}/{revision_id}/restore', [TemplateRevisionController::class, 'restore'])
        ->whereNumber('template_id')
        ->whereNumber('revision_id');

    // Template validation, duplication, conflicts, and preview
    Route::get('template-publish-validate/{id}', [TemplatePublishValidationController::class, 'check'])
        ->whereNumber('id');
    Route::post('template-duplicate/{id}', [TemplateDuplicateController::class, 'duplicate'])
        ->whereNumber('id');
    Route::get('template-conflicts/{id}', [TemplateConflictController::class, 'check'])
        ->whereNumber('id');
    Route::match(['get', 'post'], 'template-preview/{template_id}', [TemplatePreviewController::class, 'preview'])
        ->whereNumber('template_id');

    // Template trash
    Route::get('template-trash', [TemplateTrashController::class, 'trashed']);
    Route::post('template-bulk-trash', [TemplateTrashController::class, 'bulkTrash']);
    Route::post('template-bulk-restore', [TemplateTrashController::class, 'bulkRestore']);
    Route::post('template-bulk-force-delete', [TemplateTrashController::class, 'bulkForceDelete']);
    Route::post('template-empty-trash', [TemplateTrashController::class, 'emptyTrash']);
    Route::post('template-trash/{id}', [TemplateTrashController::class, 'trash'])->whereNumber('id');
    Route::post('template-restore/{id}', [TemplateTrashController::class, 'restore'])->whereNumber('id');
    Route::delete('template-force-delete/{id}', [TemplateTrashController::class, 'forceDelete'])
        ->whereNumber('id');

    // Template display conditions
    Route::get('template-conditions-list/{template_id}', [TemplateDisplayConditionController::class, 'index'])
        ->whereNumber('template_id');
    Route::post('template-conditions-replace', [TemplateDisplayConditionController::class, 'replace']);
    Route::post('template-conditions-create', [TemplateDisplayConditionController::class, 'create']);
    Route::post('template-conditions-update', [TemplateDisplayConditionController::class, 'update']);
    Route::delete('template-conditions-delete/{id}', [TemplateDisplayConditionController::class, 'destroy'])
        ->whereNumber('id');

    // Template builder layout
    Route::get('template-builder-show/{template_id}', [TemplateBuilderController::class, 'show'])
        ->whereNumber('template_id');
    Route::post('template-builder-save/{template_id}', [TemplateBuilderController::class, 'save'])
        ->whereNumber('template_id');

    // Page builder context
    Route::post('page-builder/context', [PageBuilderContextController::class, 'resolve']);

    /*
    |--------------------------------------------------------------------------
    | Post Types
    |--------------------------------------------------------------------------
    */

    // Static and utility routes must remain before parameter routes
    Route::get('post-types-support-options', [PostTypeController::class, 'supportOptions']);
    Route::get('post-types-menu', [PostTypeController::class, 'menu']);
    Route::get('post-types/trash', [PostTypeController::class, 'trash']);
    Route::post('post-types/bulk-delete', [PostTypeController::class, 'bulkDelete']);
    Route::post('post-types/bulk-restore', [PostTypeController::class, 'bulkRestore']);
    Route::delete('post-types/bulk-force-delete', [PostTypeController::class, 'bulkForceDelete']);
    Route::get('post-types/export-csv', [PostTypeExportImportController::class, 'exportToCsv']);
    Route::post('post-types/import-csv', [PostTypeExportImportController::class, 'importFromCsv']);

    // Post type CRUD
    Route::get('post-types', [PostTypeController::class, 'index']);
    Route::post('post-types', [PostTypeController::class, 'store']);
    Route::post('post-types/{id}/restore', [PostTypeController::class, 'restore'])->whereNumber('id');
    Route::delete('post-types/{id}/force-delete', [PostTypeController::class, 'forceDelete'])
        ->whereNumber('id');
    Route::get('post-types/{postType}/fields', [PostTypeController::class, 'fields']);
    Route::get('post-types/{postType}', [PostTypeController::class, 'show']);
    Route::put('post-types/{postType}', [PostTypeController::class, 'update']);
    Route::delete('post-types/{postType}', [PostTypeController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Dynamic Posts
    |--------------------------------------------------------------------------
    */

    // Dropdowns, helpers, and assignments
    Route::get('dynamic-posts/dropdown', [DynamicPostController::class, 'dropdownByPostType']);
    Route::get('dynamic-posts/by-post-type/{slug}', [DynamicPostController::class, 'byPostType']);
    Route::get('dynamic-post-form/{postType}', [DynamicPostController::class, 'formOptions']);
    Route::post('resolve-custom-fields', [DynamicPostController::class, 'resolveCustomFieldsForCreate']);
    Route::get('custom-fields', [DynamicPostController::class, 'customFieldsByPostType']);
    Route::get('dynamic-post-keyword-suggestions', [DynamicPostController::class, 'keywordSuggestions']);
    Route::get('dynamic-post-assignment/users', [DynamicPostController::class, 'assignmentUserDropdown']);
    Route::get('dynamic-post-assignment/roles', [DynamicPostController::class, 'assignmentRoleDropdown']);

    // Media Batch Upload System (Fast Asynchronous Local Uploads)
    Route::post('media/batch-upload', [\App\Http\Controllers\Api\Media\MediaBatchController::class, 'upload']);
    Route::get('media/batch-status/{batch_uuid}', [\App\Http\Controllers\Api\Media\MediaBatchController::class, 'status']);

    // Dynamic post bulk and CSV routes
    Route::post('dynamic-posts/bulk-delete', [DynamicPostController::class, 'bulkDelete']);
    Route::get('dynamic-posts/template-csv', [DynamicPostCsvController::class, 'template']);
    Route::get('dynamic-posts/export-csv', [DynamicPostCsvController::class, 'export']);
    Route::post('dynamic-posts/import-csv', [DynamicPostCsvController::class, 'import']);

    // Dynamic post CRUD
    Route::post('dynamic-posts', [DynamicPostController::class, 'store']);
    Route::get('dynamic-posts', [DynamicPostController::class, 'index']);
    Route::get('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'show'])
        ->whereNumber('dynamicPost');

    Route::put('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'update'])
        ->whereNumber('dynamicPost');

    Route::post('dynamic-posts/{dynamicPost}/update', [DynamicPostController::class, 'update'])
        ->whereNumber('dynamicPost');

    Route::delete('dynamic-posts/{dynamicPost}', [DynamicPostController::class, 'destroy'])
        ->whereNumber('dynamicPost');

    /*
    |--------------------------------------------------------------------------
    | Taxonomies
    |--------------------------------------------------------------------------
    */

    // Static and utility routes must remain before parameter routes
    Route::get('taxonomies-tree', [TaxonomyController::class, 'tree']);
    Route::get('taxonomies/export-csv', [TaxonomyExportImportController::class, 'exportToCsv']);
    Route::post('taxonomies/import-csv', [TaxonomyExportImportController::class, 'importFromCsv']);
    Route::get('taxonomies/trash', [TaxonomyController::class, 'trash']);
    Route::post('taxonomies/bulk-delete', [TaxonomyController::class, 'bulkDelete']);
    Route::post('taxonomies/bulk-restore', [TaxonomyController::class, 'bulkRestore']);
    Route::post('taxonomies/bulk-force-delete', [TaxonomyController::class, 'bulkForceDelete']);

    // Taxonomy CRUD and relationships
    Route::get('taxonomies', [TaxonomyController::class, 'index']);
    Route::post('taxonomies', [TaxonomyController::class, 'store']);
    Route::get('taxonomies/{taxonomy}/terms', [TaxonomyController::class, 'terms']);
    Route::get('taxonomies/{taxonomy}/fields', [TaxonomyController::class, 'fields']);
    Route::post('taxonomies/{id}/restore', [TaxonomyController::class, 'restore'])->whereNumber('id');
    Route::delete('taxonomies/{id}/force-delete', [TaxonomyController::class, 'forceDelete'])
        ->whereNumber('id');
    Route::get('taxonomies/{taxonomy}', [TaxonomyController::class, 'show']);
    Route::put('taxonomies/{taxonomy}', [TaxonomyController::class, 'update']);
    Route::delete('taxonomies/{taxonomy}', [TaxonomyController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Taxonomy Terms
    |--------------------------------------------------------------------------
    */

    Route::post('taxonomy-terms/bulk-delete', [TaxonomyTermController::class, 'bulkDelete']);
    Route::get('term-relations/taxonomies', [TaxonomyTermController::class, 'relationTaxonomies']);
    Route::get('relation-taxonomies/{taxonomy}/terms', [TaxonomyTermController::class, 'relationValues']);

    Route::get('taxonomy-terms', [TaxonomyTermController::class, 'index']);
    Route::post('taxonomy-terms', [TaxonomyTermController::class, 'store']);
    Route::get('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'show'])
        ->whereNumber('taxonomyTerm');
    Route::put('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'update'])
        ->whereNumber('taxonomyTerm');
    Route::delete('taxonomy-terms/{taxonomyTerm}', [TaxonomyTermController::class, 'destroy'])
        ->whereNumber('taxonomyTerm');

    /*
    |--------------------------------------------------------------------------
    | Post Taxonomy Terms
    |--------------------------------------------------------------------------
    */

    Route::post('post-taxonomy-terms/sync', [PostTaxonomyTermController::class, 'sync']);
    Route::post('post-taxonomy-terms/bulk-delete', [PostTaxonomyTermController::class, 'bulkDelete']);

    Route::get('post-taxonomy-terms', [PostTaxonomyTermController::class, 'index']);
    Route::post('post-taxonomy-terms', [PostTaxonomyTermController::class, 'store']);
    Route::get('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'show'])
        ->whereNumber('postTaxonomyTerm');
    Route::put('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'update'])
        ->whereNumber('postTaxonomyTerm');
    Route::delete('post-taxonomy-terms/{postTaxonomyTerm}', [PostTaxonomyTermController::class, 'destroy'])
        ->whereNumber('postTaxonomyTerm');

    /*
    |--------------------------------------------------------------------------
    | Custom Field Groups and Fields
    |--------------------------------------------------------------------------
    */

    // Dropdown and lookup routes
    Route::get('post-types-list', [CustomFieldGroupController::class, 'postTypesList']);
    Route::get('taxonomies-list', [CustomFieldGroupController::class, 'taxonomiesList']);
    Route::get('taxonomy-terms-list/{taxonomyId}', [CustomFieldGroupController::class, 'taxonomyTermsList'])
        ->whereNumber('taxonomyId');
    Route::get('custom-field-groups-by-post-type/{postType}', [CustomFieldGroupController::class, 'groupsByPostType']);
    Route::post('custom-field-groups-by-taxonomy/{taxonomy}', [CustomFieldGroupController::class, 'groupsByTaxonomy']);

    // Custom field group utility routes
    Route::get('custom-field-groups/export-csv', [CustomFieldGroupExportImportController::class, 'exportToCsv']);
    Route::post('custom-field-groups/import-csv', [CustomFieldGroupExportImportController::class, 'importFromCsv']);
    Route::post('custom-field-groups-bulk-delete', [CustomFieldGroupController::class, 'bulkDelete']);

    // Custom field group CRUD
    Route::get('custom-field-groups', [CustomFieldGroupController::class, 'index']);
    Route::post('custom-field-groups', [CustomFieldGroupController::class, 'store']);

    // Nested custom fields
    Route::post('custom-field-groups/{groupId}/fields', [CustomFieldGroupController::class, 'storeField'])
        ->whereNumber('groupId');
    Route::post('custom-field-groups/{groupId}/fields/reorder', [CustomFieldGroupController::class, 'reorderFields'])
        ->whereNumber('groupId');
    Route::put('custom-field-groups/{groupId}/fields/{fieldId}', [CustomFieldGroupController::class, 'updateField'])
        ->whereNumber('groupId')
        ->whereNumber('fieldId');
    Route::delete('custom-field-groups/{groupId}/fields/{fieldId}', [CustomFieldGroupController::class, 'destroyField'])
        ->whereNumber('groupId')
        ->whereNumber('fieldId');

    Route::get('custom-field-groups/{id}', [CustomFieldGroupController::class, 'show'])->whereNumber('id');
    Route::put('custom-field-groups/{id}', [CustomFieldGroupController::class, 'update'])->whereNumber('id');
    Route::delete('custom-field-groups/{id}', [CustomFieldGroupController::class, 'destroy'])->whereNumber('id');

    // Direct custom field routes
    Route::get('custom-fields-paginated', [CustomFieldGroupController::class, 'fieldsIndex']);
    Route::post('custom-fields/bulk-delete', [CustomFieldGroupController::class, 'bulkDeleteFields']);
    Route::get('custom-fields/{fieldId}', [CustomFieldGroupController::class, 'showFieldById'])
        ->whereNumber('fieldId');
    Route::match(['put', 'patch'], 'custom-fields/{fieldId}', [CustomFieldGroupController::class, 'updateFieldById'])
        ->whereNumber('fieldId');
    Route::delete('custom-fields/{fieldId}', [CustomFieldGroupController::class, 'destroyFieldById'])
        ->whereNumber('fieldId');

    // Delete & slug check aliases
    Route::match(['delete', 'post', 'get'], 'bulk-delete-custom-fields-by-id/{fieldId?}', [CustomFieldGroupController::class, 'deleteCustomFieldsById']);
    Route::match(['delete', 'post', 'get'], 'bulk-delete-custom-fields/{fieldId?}', [CustomFieldGroupController::class, 'deleteCustomFieldsById']);
    Route::match(['delete', 'post', 'get'], 'delete-custom-fields-by-id/{fieldId?}', [CustomFieldGroupController::class, 'deleteCustomFieldsById']);
    Route::match(['delete', 'post', 'get'], 'delete-custom-fields/{fieldId?}', [CustomFieldGroupController::class, 'deleteCustomFieldsById']);
    Route::match(['delete', 'post', 'get'], 'delete-custom-field-group-by-id/{id?}', [CustomFieldGroupController::class, 'destroy']);
    Route::match(['get', 'post'], 'slug-uniqueness-check', [CustomFieldGroupController::class, 'slugUniquenessCheck']);
    Route::match(['get', 'post'], 'custom-fields/slug-uniqueness-check', [CustomFieldGroupController::class, 'slugUniquenessCheck']);
    Route::match(['get', 'post'], 'custom-field-groups/slug-uniqueness-check', [CustomFieldGroupController::class, 'slugUniquenessCheck']);
    Route::match(['get', 'post'], 'check-slug-uniqueness', [CustomFieldGroupController::class, 'slugUniquenessCheck']);

    // Field types listing
    Route::get('custom-field-types', [CustomFieldGroupController::class, 'getFieldTypes']);
    Route::get('field-types', [CustomFieldGroupController::class, 'getFieldTypes']);
    Route::get('custom-field-groups/field-types', [CustomFieldGroupController::class, 'getFieldTypes']);

    /*
    |--------------------------------------------------------------------------
    | Page Builder
    |--------------------------------------------------------------------------
    */

    Route::prefix('page-builder')->group(function () {
        Route::get('/widgets', [WidgetApiController::class, 'index']);
        Route::get('/dynamic-fields', [DynamicFieldApiController::class, 'index']);
        Route::get('/widgets/{type}', [WidgetApiController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | Keywords
    |--------------------------------------------------------------------------
    */

    // Options and analytics
    Route::get('keywords-analytics', [KeywordController::class, 'analytics']);
    Route::get('keywords-options-keyword-types', [KeywordController::class, 'keywordTypes']);
    Route::get('keywords-options-listings/{keywordType}', [KeywordController::class, 'listings']);

    // Import and export
    Route::post('keywords-import-upload', [KeywordImportController::class, 'upload']);
    Route::get('keywords-import-headers/{uploadId}', [KeywordImportController::class, 'headers']);
    Route::post('keywords-import-map', [KeywordImportController::class, 'map']);
    Route::post('keywords-import-validate', [KeywordImportController::class, 'validateImport']);
    Route::post('keywords-import-confirm', [KeywordImportController::class, 'confirm']);
    Route::get('keywords-import-progress/{batchId}', [KeywordImportController::class, 'progress']);
    Route::get('keywords-export', [KeywordExportController::class, 'export']);
    Route::get('keywords-template', [KeywordExportController::class, 'template']);

    // Keyword CRUD and status
    Route::get('keywords-list', [KeywordController::class, 'index']);
    Route::post('keywords-create', [KeywordController::class, 'store']);
    Route::post('keywords-bulk-delete', [KeywordController::class, 'bulkDelete']);
    Route::get('keywords-show/{id}', [KeywordController::class, 'show']);
    Route::post('keywords-update/{id}', [KeywordController::class, 'update']);
    Route::post('keywords-status/{id}', [KeywordController::class, 'changeStatus']);
    Route::delete('keywords-delete/{id}', [KeywordController::class, 'destroy']);
});

Route::middleware(['throttle:60,1', 'admin.token', 'validate.api.client'])->prefix('admin/featured-properties')->group(function () {
    Route::get('property-options', [FeaturedPropertyController::class, 'propertyOptions']);
    Route::get('/', [FeaturedPropertyController::class, 'index']);
    Route::post('/', [FeaturedPropertyController::class, 'store']);
    Route::get('{featuredProperty}', [FeaturedPropertyController::class, 'show'])->whereNumber('featuredProperty');
    Route::put('{featuredProperty}', [FeaturedPropertyController::class, 'update'])->whereNumber('featuredProperty');
    Route::patch('{featuredProperty}', [FeaturedPropertyController::class, 'update'])->whereNumber('featuredProperty');
    Route::delete('{featuredProperty}', [FeaturedPropertyController::class, 'cancel'])->whereNumber('featuredProperty');
});
Route::middleware(['throttle:60,1', 'validate.api.client'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Dynamic Post Template Resolution
    |--------------------------------------------------------------------------
    */

    Route::get('dynamic-posts/template/{slug}', [TemplateResolveController::class, 'showDynamicPostTemplateBySlug']);
    Route::get('dynamic-posts/{dynamicPost}/template', [TemplateResolveController::class, 'showDynamicPostTemplate'])
        ->whereNumber('dynamicPost');

    /*
    |--------------------------------------------------------------------------
    | Dynamic Post Form Steps
    |--------------------------------------------------------------------------
    */

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

Route::middleware(['validate.api.client'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Authentication and User Profile
    |--------------------------------------------------------------------------
    */

    Route::get('auth/getuser', [UserProfileController::class, 'show']);
    Route::post('auth/profile/personal', [UserProfileController::class, 'updatePersonal']);
    Route::post('auth/profile/address', [UserProfileController::class, 'updateAddress']);
    Route::post('auth/profile/photo', [UserProfileController::class, 'updatePhoto']);
    Route::post('auth/profile/company-logo', [UserProfileController::class, 'updateCompanyLogo']);
    Route::post('auth/profile/password', [UserProfileController::class, 'updatePassword']);



    // Registration checks
    Route::post('/check-user-duplicate', [Rolecontroller::class, 'checkUserDuplicate']);
    Route::post('/verify-register-otp', [AuthController::class, 'verifyRegisterOtp']);

    /*
    |--------------------------------------------------------------------------
    | Frontend Listing Helpers
    |--------------------------------------------------------------------------
    */

    Route::get('frontend/listing-roles', [DynamicPostController::class, 'frontendListingRoleDropdown']);
    Route::get(
        'frontend/dynamic-post-step-form/{postType}',
        [DynamicPostFormStepController::class, 'frontendForm']
    );

    Route::prefix('frontend')->name('frontend.listings.')->group(function () {
        Route::get('locations/countries', [FrontendLocationController::class, 'countries']);
        Route::get('locations/states', [FrontendLocationController::class, 'states']);
        Route::get('locations/cities', [FrontendLocationController::class, 'cities']);
        Route::get('locations/selected', [FrontendLocationController::class, 'selected']);
        Route::get('locations/popular-cities', [FrontendLocationController::class, 'popularCities']);
        Route::get('locations/header-cities', [FrontendLocationController::class, 'headerCities']);
        Route::get('/taxonomies', [FrontendListingController::class, 'taxonomies'])->name('taxonomies');
    });

    Route::get('get-popular-cities', [FrontendLocationController::class, 'popularCities']);
    Route::get('get-header-cities', [FrontendLocationController::class, 'headerCities']);
    Route::prefix('frontend')
        ->middleware(['validate.api.client', 'throttle:api'])
        ->group(function () {
            Route::get('property-search/options', [PropertySearchController::class, 'options']);
            Route::get('property-search/location-suggestions', [PropertySearchController::class, 'locationSuggestions']);
            Route::get('properties/search', [PropertySearchController::class, 'search']);

            Route::get('city-explore', [CityExploreController::class, 'index']);
            Route::get('city-explore/agents', [CityExploreController::class, 'agents']);
            Route::get('city-explore/developers', [CityExploreController::class, 'developers']);
            Route::get('city-explore/featured-properties', [CityExploreController::class, 'featuredProperties']);
            Route::get('city-explore/featured-projects', [CityExploreController::class, 'featuredProjects']);
            Route::get('city-explore/sponsored-properties', [CityExploreController::class, 'sponsoredProperties']);
            Route::get('city-explore/sponsored-projects', [CityExploreController::class, 'sponsoredProjects']);
            Route::get('client-reviews', [ClientReviewController::class, 'index']);
        });
    /*
    |--------------------------------------------------------------------------
    | User Property Listings
    |--------------------------------------------------------------------------
    */

    Route::get('user-listing-analytics', [UserListingController::class, 'analytics']);
    Route::get('frontend/listings', [UserListingController::class, 'index']);
    Route::post('frontend/listings', [UserListingController::class, 'store'])
        ->middleware(['kyc.publish', 'membership.listing']);
    Route::middleware(['throttle:30,1', 'kyc.publish'])->get('frontend/listings/{listing}', [UserListingController::class, 'show']);
    Route::post('frontend/listings/{listing}/update', [UserListingController::class, 'update'])
        ->middleware(['kyc.publish:published_only', 'membership.listing:published_only'])
        ->whereNumber('listing');

    Route::delete('frontend/listings/{listing}', [UserListingController::class, 'destroy'])
        ->whereNumber('listing');

    Route::get(
        'frontend/listings/{property}/verification-status',
        [UserPropertyVerificationController::class, 'status']
    )->whereNumber('property');

    Route::get(
        'frontend/listings/{property}/timeline',
        [UserPropertyVerificationController::class, 'timeline']
    )->whereNumber('property');

    Route::post(
        'frontend/listings/{listing_id}/feature',
        [UserMembershipFeatureUsageController::class, 'featureListing']
    )->whereNumber('listing_id');

    Route::post(
        'frontend/listings/{listing_id}/unfeature',
        [UserMembershipFeatureUsageController::class, 'unfeatureListing']
    )->whereNumber('listing_id');

    Route::post(
        'frontend/listings/{listing_id}/toggle-featured',
        [UserMembershipFeatureUsageController::class, 'toggleFeaturedListing']
    )->whereNumber('listing_id');

    Route::get(
        'frontend/listings/{listingId}/featured-status',
        [UserMembershipFeatureUsageController::class, 'featuredStatus']
    )->whereNumber('listingId');

    /*
    |--------------------------------------------------------------------------
    | Related Posts Widget
    |--------------------------------------------------------------------------
    */

    Route::get('template-builder/related-posts-widget/schema', [RelatedPostsWidgetPreviewController::class, 'schema']);
    Route::post('template-builder/related-posts-widget/preview', [RelatedPostsWidgetPreviewController::class, 'preview']);
    Route::post('template-builder/related-posts-widget/candidates', [RelatedPostsWidgetCandidateController::class, 'candidates']);

    Route::middleware(['throttle:kyc-user'])->prefix('kyc')->group(function () {
        Route::get('status', [UserKycController::class, 'status']);
        Route::get('details', [UserKycController::class, 'details']);
        Route::get('documents', [UserKycController::class, 'documents']);
        Route::get('timeline', [UserKycController::class, 'timeline']);

        Route::middleware(['throttle:kyc-upload'])->group(function () {
            Route::post('uploads/start', [UserKycController::class, 'startBatchUpload']);
            Route::get('uploads/{uploadId}/progress', [UserKycController::class, 'uploadProgress']);
        });

        Route::post('submit', [UserKycController::class, 'submit']);
        Route::post('resubmit', [UserKycController::class, 'resubmit']);

        Route::get('documents/{documentId}/view', [UserKycController::class, 'viewDocument'])
            ->whereNumber('documentId');
    });
    Route::prefix('membership')->group(function () {
        /*
    |--------------------------------------------------------------------------
    | Public membership APIs
    |--------------------------------------------------------------------------
    */
        Route::middleware('throttle:membership-public')->group(function () {
            Route::get('plans', [UserMembershipController::class, 'plans']);
            Route::get('plans/{plan}', [UserMembershipController::class, 'showPlan']);

            Route::get('addons', [UserMembershipAddonController::class, 'addons']);
            Route::get('addons/{addon}', [UserMembershipAddonController::class, 'showAddon']);
        });

        /*
    |--------------------------------------------------------------------------
    | Authenticated user membership APIs
    |--------------------------------------------------------------------------
    */
        Route::middleware('throttle:membership-user')->group(function () {
            Route::get('my-status', [UserMembershipController::class, 'myStatus']);
            Route::get('my-credits', [UserMembershipController::class, 'myCredits']);

            Route::get('orders', [UserMembershipController::class, 'orders']);
            Route::get('orders/{order}', [UserMembershipController::class, 'showOrder']);

            Route::get('addon-orders', [UserMembershipAddonController::class, 'addonOrders']);
            Route::get('addon-orders/{order}', [UserMembershipAddonController::class, 'showAddonOrder']);

            Route::get('invoices', [UserMembershipInvoiceController::class, 'index']);
            Route::get('invoices/{invoice}', [UserMembershipInvoiceController::class, 'show']);
            Route::get('invoices/{invoice}/download', [UserMembershipInvoiceController::class, 'download']);

            Route::get('notifications', [UserMembershipNotificationController::class, 'index']);
            Route::post('notifications/{notification}/read', [UserMembershipNotificationController::class, 'markAsRead']);
            Route::post('notifications/read-all', [UserMembershipNotificationController::class, 'markAllAsRead']);
        });

        /*
    |--------------------------------------------------------------------------
    | Payment/order APIs
    |--------------------------------------------------------------------------
    */
        Route::middleware('throttle:membership-payment')->group(function () {
            Route::post('orders', [UserMembershipController::class, 'createOrder']);
            Route::post('orders/{order}/razorpay', [UserMembershipController::class, 'createRazorpayOrder']);
            Route::post('payments/verify', [UserMembershipController::class, 'verifyPayment']);

            Route::post('addon-orders', [UserMembershipAddonController::class, 'createAddonOrder']);
            Route::post('addon-orders/{order}/razorpay', [UserMembershipAddonController::class, 'createRazorpayAddonOrder']);
            Route::post('addon-payments/verify', [UserMembershipAddonController::class, 'verifyAddonPayment']);
        });

        /*
    |--------------------------------------------------------------------------
    | Feature usage APIs
    |--------------------------------------------------------------------------
    */
        Route::middleware('throttle:membership-feature-usage')->group(function () {
            Route::post('leads/unlock', [UserMembershipFeatureUsageController::class, 'unlockLead']);
            Route::post('features/consume', [UserMembershipFeatureUsageController::class, 'consumeFeature']);

            Route::post('feature-listing/star', [UserMembershipFeatureUsageController::class, 'featureListing']);
            Route::post('feature-listing/unstar', [UserMembershipFeatureUsageController::class, 'unfeatureListing']);
            Route::post('feature-listing/toggle', [UserMembershipFeatureUsageController::class, 'toggleFeaturedListing']);
            Route::get('feature-listing/status/{listingId}', [UserMembershipFeatureUsageController::class, 'featuredStatus'])
                ->whereNumber('listingId');
        });
    });
    /*
|--------------------------------------------------------------------------
| User Notification Devices
|--------------------------------------------------------------------------
*/
    Route::prefix('notifications')
        ->middleware([
            'validate.api.client',
            'allrole.token',
            'throttle:notification-device',
        ])
        ->group(function () {
            Route::get('devices', [UserNotificationDeviceController::class, 'index']);
            Route::post('devices/register', [UserNotificationDeviceController::class, 'register']);
            Route::post('devices/revoke', [UserNotificationDeviceController::class, 'revoke']);
        });


    /*
|--------------------------------------------------------------------------
| User Notifications / Topics
|--------------------------------------------------------------------------
*/
    Route::prefix('notifications')
        ->middleware([
            'validate.api.client',
            'allrole.token',
            'throttle:notification-user',
        ])
        ->group(function () {
            /*
        |--------------------------------------------------------------------------
        | User Topics
        |--------------------------------------------------------------------------
        */
            Route::get('topics', [UserNotificationTopicController::class, 'index']);

            Route::post('topics/{topic}/subscribe', [UserNotificationTopicController::class, 'subscribe'])
                ->whereNumber('topic');

            Route::post('topics/{topic}/unsubscribe', [UserNotificationTopicController::class, 'unsubscribe'])
                ->whereNumber('topic');


            /*
        |--------------------------------------------------------------------------
        | User Notification Inbox
        |--------------------------------------------------------------------------
        */
            Route::get('/', [UserNotificationController::class, 'index']);

            Route::get('unread-count', [UserNotificationController::class, 'unreadCount']);

            Route::post('read-all', [UserNotificationController::class, 'markAllAsRead']);

            Route::get('{notification}', [UserNotificationController::class, 'show'])
                ->whereNumber('notification');

            Route::post('{notification}/read', [UserNotificationController::class, 'markAsRead'])
                ->whereNumber('notification');

            Route::delete('{notification}', [UserNotificationController::class, 'destroy'])
                ->whereNumber('notification');
        });
    Route::get('membership/me/access', [MembershipAccessController::class, 'me'])
        ->middleware('throttle:membership-user');
});
Route::middleware([
    'validate.api.client',
])->group(function () {
    Route::patch(
        'user-listings/{property}/availability',
        [
            PropertyAvailabilityController::class,
            'ownerUpdate',
        ]
    )->middleware(
            'throttle:property-availability-owner'
        );

    Route::get(
        'user-listings/{property}/availability-history',
        [
            PropertyAvailabilityController::class,
            'ownerHistory',
        ]
    )->middleware(
            'throttle:property-availability-owner'
        );
});
Route::middleware([
    'throttle:120,1',
])
    ->prefix('guest/posts')
    ->group(function () {

        /*
         * All / Featured listing.
         */
        Route::get(
            '{postType}',
            [
                GuestDynamicPostController::class,
                'index',
            ]
        );

        /*
         * DynamicPost detail.
         */
        Route::get(
            '{postType}/{dynamicPostId}',
            [
                GuestDynamicPostController::class,
                'show',
            ]
        )
            ->whereNumber(
                'dynamicPostId'
            );

        /*
         * Dynamic Related Posts.
         */
        Route::get(
            '{postType}/{dynamicPostId}/related',
            [
                GuestDynamicPostController::class,
                'related',
            ]
        )
            ->whereNumber(
                'dynamicPostId'
            );
    });

Route::middleware(['throttle:120,1'])->get('guest/related-posts', [GuestDynamicPostController::class, 'relatedPosts']);
Route::middleware(['throttle:120,1'])->get('related-posts', [GuestDynamicPostController::class, 'relatedPosts']);

/*
 * Dynamic Recently Viewed Posts.
 */
Route::middleware(['throttle:120,1'])->group(function () {
    Route::get('guest/recently-viewed', [RecentlyViewedPostController::class, 'index']);
    Route::post('guest/recently-viewed', [RecentlyViewedPostController::class, 'track']);
    Route::delete('guest/recently-viewed', [RecentlyViewedPostController::class, 'clear']);

    Route::get('recently-viewed', [RecentlyViewedPostController::class, 'index']);
    Route::post('recently-viewed', [RecentlyViewedPostController::class, 'track']);
    Route::delete('recently-viewed', [RecentlyViewedPostController::class, 'clear']);
});

/*
 * Dynamic Guest Agents APIs (Single Agent Details & All Agents Listing).
 */
Route::middleware(['throttle:120,1'])->group(function () {
    Route::get('guest/agents', [GuestAgentController::class, 'index']);
    Route::get('guest/agents/{agentId}', [GuestAgentController::class, 'show']);

    Route::get('frontend/agents', [GuestAgentController::class, 'index']);
    Route::get('frontend/agents/{agentId}', [GuestAgentController::class, 'show']);
});

Route::post('membership/webhooks/razorpay', [RazorpayWebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Magicbricks-Style Property Search & Filter API (v1)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['throttle:api'])->group(function () {
    Route::get('properties/search', [\App\Http\Controllers\Api\PropertySearchController::class, 'search']);
    Route::get('properties/filter-options', [\App\Http\Controllers\Api\PropertySearchController::class, 'filterOptions']);
});
