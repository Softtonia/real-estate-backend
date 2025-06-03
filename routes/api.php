<?php

use App\Http\Controllers\CustomMultipleFieldController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\HelpActivityController;
use App\Http\Controllers\SiteSetting\SiteSettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ForgotPasswordController;
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
use App\Http\Controllers\Page\privacypolicycontroller;
use App\Http\Controllers\Page\termsandconditioncontroller;
use App\Http\Controllers\Page\servicescontroller;
use App\Http\Controllers\Page\AboutusController;
use App\Http\Controllers\Page\CareerController;
use App\Http\Controllers\Page\LegalController;
use App\Http\Controllers\Page\SalesRefundController;
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

Route::post('/register', [UserController::class, 'register']);
Route::post('email-verify-otp', [UserController::class, 'emailverifyOTP']);
Route::post('/store-otp-verification-data', [UserController::class, 'storeOtpVerificationData']);
Route::post('login', [UserController::class, 'loginOldUser']);
Route::post('login', [UserController::class, 'login']);
Route::get('/logout', [UserController::class, 'logout']);
Route::post('/check-unique', [UserController::class, 'checkUnique']);
Route::post('/admin/profile/change-password', [UserController::class, 'changePassword'])->middleware('api.token');
Route::post('forget-password', [Usercontroller::class, 'ForgetPassword']);

Route::middleware('admin.token')->post('/user/search', [UserController::class, 'SearchUser']);
Route::middleware('admin.token')->get('all-user-listing', [UserController::class, 'alluserlist']);
// Route::get('get-details-byuserid', [UserController::class, 'getdetailsbyuserid']);


Route::middleware('adminOrCurrentUser')->get('get-details-byuserid', [UserController::class, 'getdetailsbyuserid']); // Done By softtonia
Route::middleware('admin.token')->post('update-user-byuserid', [UserController::class, 'updateuserbyid']);
Route::middleware('admin.token')->post('update-user-byuserid', [UserController::class, 'updateuserbyid']);
Route::middleware('admin.token')->post('update-user-status', [UserController::class, 'updateuserstatus']);
Route::middleware('admin.token')->post('create-user', [UserController::class, 'createUser']);
Route::middleware('allrole.token')->post('update-front-user-by-id', [UserController::class, 'updateUser']);
Route::middleware('admin.token')->post('delete-user', [UserController::class, 'deleteUser']);
Route::post('user-bulk-delete', [UserController::class, 'bulkDelete']);
Route::get('/users/filter-by-role', [UserController::class, 'filterByRole']);
Route::get('/users/filter-by-status', [UserController::class, 'filterByStatus']);



Route::middleware('OnlyCompany')->get('get-company-consultancy-listing', [UserController::class, 'getCompanyConsultancyListing']);   // Done By softtonia
Route::middleware('OnlyCompany')->get('search-consultancy-by-id', [UserController::class, 'searchConsultancyById']);  // Done By softtonia
Route::middleware('OnlyCompany')->post('send-request-by-company-to-consultancy', [UserController::class, 'sendRequestByCompanyToConsultancy']); // Done By softtonia
Route::middleware('OnlyConsultancy')->get('get-all-consultancy-join-request-listing', [UserController::class, 'getConsultancyAllJoinRequest']);  // Done By softtonia
Route::middleware(['allowed_roles'])->post('accept-decline-company-request-by-consultancy', [UserController::class, 'acceptDeclineCompanyRequestByConsultancy']); // Done By softtonia
Route::middleware('OnlyConsultancy')->post('leave-the-comapny-by-consultancy', [UserController::class, 'leaveTheComapnyByConsultancy']); // Done By softtonia
Route::middleware('OnlyConsultancy')->get('get-consultancy-details-with-company', [UserController::class, 'getConsultancyDetailsWithCompany']);  // Done By softtonia

Route::middleware('OnlyCompany')->get('get-company-project-listing', [UserController::class, 'getCompanyProjectListing']); // Done By softtonia
Route::middleware('OnlyCompany')->get('fetch-assigned-project-of-company', [UserController::class, 'fetchAssignedProjectOfCompany']); // Done By softtonia
Route::post('property-details-by-projectId', [UserController::class, 'propertyDetailsByProjectId']);
Route::middleware('OnlyConsultancy')->get('fetch-total-assigned-project-to-consultancy', [UserController::class, 'fetchTotalAssignedProjectToConsultancy']);
Route::get('fetch-consultancy-total-assigned-project', [UserController::class, 'fetchConsultancyTotalAssignedProjects']);
Route::post('assign-project-to-agent-by-consultancy', [UserController::class, 'assignProjectToAgentByConsultancy']);
Route::get('fetch-assigned-project-of-agent', [UserController::class, 'fetchAssignedProjectOfAgent']);
Route::get('fetch-agent-total-assigned-project', [UserController::class, 'fetchAgentTotalAssignedProject']);
Route::get('fetch-total-project-of-consultancy', [UserController::class, 'fetchTotalProjectOfConsultancy']);
Route::post('view-project-details-of-consultancy', [UserController::class, 'viewProjectDetailsOfConsultancy']);
Route::post('view-project-details-of-company', [UserController::class, 'viewProjectDetailsOfCompany']);
Route::post('search-property', [UserController::class, 'searchProperty']);
Route::get('listing-of-all-owner-property', [UserController::class, 'listingOfAllOwnerProperty']);
Route::get('listing-of-all-projects', [UserController::class, 'listingOfAllProjects']);
Route::get('listing-of-ready-to-move-property', [UserController::class, 'listingOfReadyToMoveProperty']);
Route::get('all-top-agent-listing', [UserController::class, 'allTopAgentListing']);
Route::get('listing-of-budge-home-property', [UserController::class, 'listingOfBudgeHomesProperty']);
Route::get('listing-of-trending-project', [UserController::class, 'listingOfAllTrendingProject']);
Route::get('listing-of-property-for-buy', [UserController::class, 'listingOfPropertyForBuy']);
Route::get('listing-of-property-for-rent', [UserController::class, 'listingOfPropertyForRent']);
Route::middleware('admin.token')->post('update-site-setting', [UserController::class, 'updateSiteSetting']);
Route::get('site-setting', [SiteSettingController::class, 'siteSetting']);
Route::get('listing-of-property-with-project', [UserController::class, 'listingOfPropertyWithProject']);

Route::get('get-all-pages', [UserController::class, 'getAllPages']);
Route::get('overview-of-all-user-property', [UserController::class, 'overviewOfProperty']);
Route::middleware('admin.token')->post('create-top-features', [UserController::class, 'createTopFeatures']);
Route::post('listing-of-top-features', [UserController::class, 'listingOfTopFeatures']);
Route::post('top-features-listing', [UserController::class, 'topFeaturesListing']);
Route::get('property-listing-by-location', [UserController::class, 'propertyListingByLocation']);
Route::get('get-user-status', [UserController::class, 'getallstatus']);

Route::get('get-all-owner-listing', [UserController::class, 'allOwnerListing']);

Route::get('get-all-company-listing', [UserController::class, 'allCompanyListing']);
Route::get('get-all-agent-listing', [UserController::class, 'allAgentListing']); //website


Route::middleware('allrole.token')->get('/kyc', [UserController::class, 'getKYCStatus']); // Get KYC status // Done By softtonia
Route::middleware('allrole.token')->post('/kyc/update', [UserController::class, 'updateKYCStatus']); // Done By softtonia
Route::middleware('admin.token')->get('get-all-consultancy-listing', [UserController::class, 'allConsultancyListing']); //Done By softtonia
Route::middleware(['admin_or_consultancy'])->get('get-consultancy-agents/{id}', [UserController::class, 'getConsultancyAgents']);
Route::middleware('company.admin')->get('get-all-consultancy-listing-by-company', [UserController::class, 'getAllConsultancyListingByCompany']); //Done By softtonia


// User route will end from here
Route::get('get-all-roles', [RoleController::class, 'getallrole']);
Route::get('get-default-roles', [RoleController::class, 'getDefaultRole']);
Route::post('/password/reset', [ResetPasswordController::class, 'reset'])->name('password.update');
Route::get('verify-email/{id}/{code}', [VerificationController::class, 'verifyEmail'])->name('verify-email');



// ========== Subscribe Emails Import ===============
Route::post('insert-subscribe-email', [UserController::class, 'insertSubscribeEmail'])->middleware('api.token');
Route::get('listing-subscribe-email', [UserController::class, 'listingOfSubscribedEmails'])->middleware('api.token');
Route::post('import-subscribed-emails', [UserController::class, 'importSubscribedEmails'])->middleware('api.token');

// ========= Subscribe Emails Export ===============
Route::get('/subscribed-emails/export/{format}', [UserController::class, 'exportSubscribedEmails'])->name('subscribed_emails.export')->middleware('api.token');

// =======Error Log Listing=================
Route::get('error-logs', [ErrorLogController::class, 'listErrorLogs'])->middleware('api.token');
Route::get('error-logs/download/{file}', [ErrorLogController::class, 'downloadFile'])->middleware('api.token');
// Single delete route
Route::delete('/error-logs/delete/{fileName}', [ErrorLogController::class, 'deleteErrorLog'])->middleware('api.token');
// Bulk delete route
Route::post('/error-logs/bulk-delete', [ErrorLogController::class, 'bulkDeleteErrorLogs'])->middleware('api.token');


// ======Project Listing============
Route::middleware('allow.admin_company')->post('add-project-listing', [ProjectlistingController::class, 'store']);
Route::middleware('allow.admin_company')->post('edit-project-listing', [ProjectlistingController::class, 'update']);
Route::middleware('allow.admin_company')->post('delete-project-listing', [ProjectlistingController::class, 'destroy']);
Route::get('get-all-project-listing', [ProjectlistingController::class, 'index']);
Route::middleware('admin.token')->get('get-all-project-listing-by-admin', [ProjectlistingController::class, 'indexByAdmin']);
Route::middleware('api.token')->get('/user-project', [ProjectlistingController::class, 'getUserProject']);

Route::get('get-data-project/{id}', [ProjectlistingController::class, 'getdatabyId']);
Route::post('update-project-status', [ProjectlistingController::class, 'updateProjectStatus']);
Route::middleware('admin.token')->post('update-project-status-by-admin', [ProjectlistingController::class, 'updateProjectStatusByAdmin']);
Route::post('get-project-by-userid', [ProjectlistingController::class, 'getProjectByUserId']);
Route::post('project-bulk-delete', [ProjectlistingController::class, 'bulkDelete']);


// ======Developer Listing============
Route::middleware('allow.admin_developer')->post('add-developer-listing', [DeveloperlistingController::class, 'store']);
Route::middleware('allow.admin_developer')->post('edit-developer-listing', [DeveloperlistingController::class, 'update']);
Route::post('allow.admin_developer', [DeveloperlistingController::class, 'destroy']);
Route::get('fetch-all-developer-listing', [DeveloperlistingController::class, 'index']);
Route::middleware('admin.token')->get('fetch-all-developer-listing-by-admin', [DeveloperlistingController::class, 'indexByAdmin']);
Route::get('get-data-developer/{id}', [DeveloperlistingController::class, 'getdatabyId']);
Route::post('developer-bulk-delete', [DeveloperlistingController::class, 'bulkDelete']);
Route::middleware('admin.token')->post('update-developer-status', [DeveloperlistingController::class, 'updateDeveloperStatus']);
Route::middleware('api.token')->get('/user-developer', [DeveloperlistingController::class, 'getUserDeveloper']);
Route::post('get-all-developer-by-location-id', [DeveloperlistingController::class, 'getAllDeveloperByLocationId']);




// =======Property Listing============
Route::middleware('api.token')->post('add-properties-listing', [PropertylistingController::class, 'store']);
Route::middleware('api.token')->post('edit-properties-listing', [PropertylistingController::class, 'update']);
Route::middleware('api.token')->post('delete-properties-listing', [PropertylistingController::class, 'destroy']);
Route::middleware('admin.token')->get('get-all-properties-listing-by-admin', [PropertylistingController::class, 'indexByadmin']);
Route::get('get-all-properties-listing', [PropertylistingController::class, 'index']);
Route::middleware('api.token')->get('/user-properties', [PropertylistingController::class, 'getUserProperties']);

Route::get('get-data-properties/{id}', [PropertylistingController::class, 'getdatabyId']);
Route::post('update-temporary-status', [PropertylistingController::class, 'updateTemporaryStatus']);
Route::get('get-temporary-statuses', [PropertylistingController::class, 'getTemporaryStatuses']);
Route::get('get-property-statuses', [PropertylistingController::class, 'getPropertyStatuses']);
Route::middleware('admin.token')->post('update-property-status', [PropertylistingController::class, 'updatePropertyStatus']);
Route::post('get-all-project-by-location-id', [PropertylistingController::class, 'getAllProjectByLocationId']);
Route::post('get-company-project-by-location-id', [PropertylistingController::class, 'getComapnyProjectByLocationId']);
Route::post('properties-bulk-delete', [PropertylistingController::class, 'bulkDelete']);

// ============= Property Listing by agent and owner============
Route::middleware('allow.owner.agent')->post('post-property-create', [PropertylistingController::class, 'storeByAgentOwner']);
Route::middleware('api.token')->get('get-all-propety-listing-byusertoken', [PropertylistingController::class, 'getUserPropertiesByToken']);





// frontend site
// =======Front Property Listing============

Route::get('overview-of-user-property', [frontPropertylistingController::class, 'overviewOfProperty']);
Route::post('store-property-analytics', [frontPropertylistingController::class, 'storePropertyAnalytics']);
Route::get('list-property-analytics', [frontPropertylistingController::class, 'listPropertyAnalytics']);
Route::get('view-property-analytics', [frontPropertylistingController::class, 'viewPropertyAnalytics']);
// frontend side
// =======Front Project Listing============
Route::post('add-website-project-listing', [frontProjectlistingController::class, 'store']);
Route::post('edit-website-project-listing', [frontProjectlistingController::class, 'update']);
Route::post('delete-website-project-listing', [frontProjectlistingController::class, 'destroy']);
Route::get('get-all-website-project-listing', [frontProjectlistingController::class, 'index']);
Route::get('get-data-website-project/{id}', [frontProjectlistingController::class, 'getdatabyId']);
Route::post('update-website-project-status', [frontProjectlistingController::class, 'updateProjectStatus']);
Route::post('store-project-analytics', [frontProjectlistingController::class, 'storeProjectAnalytics']);
Route::get('list-project-analytics', [frontProjectlistingController::class, 'listProjectAnalytics']);
Route::get('view-project-analytics', [frontProjectlistingController::class, 'viewProjectAnalytics']);
Route::get('overview-of-project', [frontProjectlistingController::class, 'overviewOfProject']);

// admin route will start from here
Route::post('admin/login', [AdminController::class, 'login'])->name('login');

    Route::middleware('admin.token')->post('/profile/update', [AdminController::class, 'update']);
    // Add other routes here if needed


// Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
// dd(1);
Route::middleware(['admin'])->prefix('admin')->group(function () {
    Route::middleware(['token.expiration'])->group(function () {

        Route::middleware('admin.token')->get('/get-admin-profile', [Admincontroller::class, 'getAdminProfile']);
    });

    Route::post('/login-restricted', [Admincontroller::class, 'LoginActiveInactive']);
    Route::post('/user-bulk-delete', [Admincontroller::class, 'userAllRecordBulksDelete']);

    Route::post('/mail-config', [MailConfigController::class, 'store']);
    Route::post('/mail-config/{id}', [MailConfigController::class, 'update']);
    Route::post('/get-mail-config', [MailConfigController::class, 'getMailConfig']);
    Route::post('/active-mail-config', [MailConfigController::class, 'ActiveMailConfig']);
    Route::post('/mail-config-delete/{id}', [MailConfigController::class, 'deleteMailConfig']);

    Route::post('/create-role-prefix-repeater', [SystemController::class, 'CreateRolePrefixRepeater']);
    Route::post('/get-role-prefix-repeater', [SystemController::class, 'GetRolePrefixRepeater']);
    Route::post('/delete-role-prefix-repeater/{ic}', [SystemController::class, 'DeleteRolePrefixRepeater']);
    Route::post('/update-role-prefix-repeater-by-id/{id}', [SystemController::class, 'UpdateRolePrefixRepeater']);

    Route::post('role-create', [RoleController::class, 'createRole']);
    Route::post('role-edit', [RoleController::class, 'editRole']);
    Route::post('role-delete', [RoleController::class, 'deleteRole']);
    Route::get('role-listing/{id?}', [RoleController::class, 'index']); // Optional ID parameter
    Route::post('roles/bulk-delete', [RoleController::class, 'bulkDeleteRoles']);
    Route::post('roles/search', [RoleController::class, 'searchRole']);
});

Route::post('import-keywords', [Admincontroller::class, 'import']);
Route::get('export-keywords', [Admincontroller::class, 'export']);
Route::get('fetch-keywords', [Admincontroller::class, 'fetchKeywordList']);
Route::get('overview-of-dashboard', [Admincontroller::class, 'overviewOfDashboard']);
Route::get('fetch-property-keywords', [Admincontroller::class, 'fetchPropertyKeywordList']);
Route::get('fetch-project-keywords', [Admincontroller::class, 'fetchProjectKeywordList']);
Route::get('fetch-developer-keywords', [Admincontroller::class, 'fetchDeveloperKeywordList']);

// =======Location============
Route::post('location-create', [Locationcontroller::class, 'store']);
Route::post('location-update', [Locationcontroller::class, 'update']);
Route::get('location-listing', [Locationcontroller::class, 'index']);
Route::post('location', [Locationcontroller::class, 'destroy']);
Route::post('getdatabyId-location', [Locationcontroller::class, 'getdatabyId']);
Route::post('location-bulk-delete', [Locationcontroller::class, 'bulkDelete']);
Route::get('location-search', [Locationcontroller::class, 'searchByName'])->name('location.search');

// ======= Bulk Upload Country , State, City in CSV Format ===========

Route::post('bulk-upload-location-csv', [Locationcontroller::class, 'bulkUploadCSC']);

// =======Amenity============
Route::post('amenity-create', [Amenitycontroller::class, 'store']);
Route::post('amenity-update', [Amenitycontroller::class, 'update']);
Route::get('amenity-listing', [Amenitycontroller::class, 'index']);
Route::post('amenity', [Amenitycontroller::class, 'destroy']);
Route::post('getdatabyId-amenity', [Amenitycontroller::class, 'getdatabyId']);
Route::post('amenity-bulk-delete', [Amenitycontroller::class, 'bulkDelete']);

// =======Property Type============
Route::post('property-type-create', [Propertytypecontroller::class, 'store']);
Route::post('property-type-update', [Propertytypecontroller::class, 'update']);
Route::get('property-type-listing', [Propertytypecontroller::class, 'index']);
Route::post('property-type', [Propertytypecontroller::class, 'destroy']);
Route::post('getdatabyId-property-type', [Propertytypecontroller::class, 'getdatabyId']);
Route::get('/property-ids', [Propertytypecontroller::class, 'getPropertyIds']);
Route::post('/property-types', [Propertytypecontroller::class, 'storePropertyType']);
Route::post('property-type-bulk-delete', [Propertytypecontroller::class, 'bulkDelete']);
Route::get('property-type-search', [Propertytypecontroller::class, 'searchByName'])->name('propertytype.search');


// =======Status============
Route::post('status-create', [statuscontroller::class, 'store']);
Route::post('status-update', [statuscontroller::class, 'update']);
Route::get('status-listing', [statuscontroller::class, 'index']);
Route::post('status', [statuscontroller::class, 'destroy']);
Route::get('getdatabyId-status', [statuscontroller::class, 'getdatabyId']);
Route::post('status-bulk-delete', [statuscontroller::class, 'bulkDelete']);
Route::get('status-search', [statuscontroller::class, 'searchByName'])->name('status.search');

// =======Purpose============
Route::post('purpose-create', [PurposeController::class, 'store']);
Route::post('purpose-update', [PurposeController::class, 'update']);
Route::get('purpose-listing', [PurposeController::class, 'index']);
Route::post('purpose', [PurposeController::class, 'destroy']);
Route::post('getdatabyId-purpose', [PurposeController::class, 'getdatabyId']);
Route::post('purpose-bulk-delete', [PurposeController::class, 'bulkDelete']);
Route::get('purpose-search', [PurposeController::class, 'searchByName'])->name('purposes.search');
// =======Property============
Route::post('property-create', [Propertycontroller::class, 'store']);
Route::post('property-update', [Propertycontroller::class, 'update']);
Route::get('property-listing', [Propertycontroller::class, 'index']);
Route::post('property', [Propertycontroller::class, 'destroy']);
Route::get('properties/{id}', [PropertyController::class, 'getPropertyAndType']);
Route::post('property-bulk-delete', [PropertyController::class, 'bulkDelete']);
Route::get('property-search', [PropertyController::class, 'searchByName'])->name('property.search');



// =======Amenity Categories============
Route::post('add-amenities-categories', [AmenitycategoriesController::class, 'store']);
Route::post('edit-amenities-categories', [AmenitycategoriesController::class, 'update']);
Route::get('list-amenities-categories', [AmenitycategoriesController::class, 'index']);
Route::post('delete-amenities-categories', [AmenitycategoriesController::class, 'destroy']);
Route::post('getdatabyId-amenitycategories', [AmenitycategoriesController::class, 'getdatabyId']);
Route::post('amenities-categories-bulk-delete', [AmenitycategoriesController::class, 'bulkDelete']);
// admin route will end from here


// custom field will start from here
Route::get('custom-field-listing-by-type', [CustomFieldController::class, 'customFieldListingByType']);
Route::middleware('admin.token')->post('add-custom-fields', [CustomFieldController::class, 'store']);
Route::middleware('admin.token')->post('edit-custom-fields', [CustomFieldController::class, 'update']);
Route::middleware('admin.token')->post('delete-custom-fields', [CustomFieldController::class, 'delete']);
Route::post('get-custom-fields-byid', [CustomFieldController::class, 'show']);
Route::middleware('allrole.token')->get('model-listing', [CustomFieldController::class, 'modelListing']);
Route::get('all_template_id_listings', [CustomFieldController::class, 'customFieldUniqueCode']);
Route::middleware('allrole.token')->get('condition-listing', [CustomFieldController::class, 'conditionListing']);
Route::middleware('allrole.token')->get('custom-field-listing', [CustomFieldController::class, 'customFieldListing']);
Route::get('property-type-listing-by-propertyid', [CustomFieldController::class, 'propertyTypeListingByPropertyId']);
Route::get('property-status-listing-by-propertytype', [CustomFieldController::class, 'propertyStatusListingByPropertyType']);
Route::get('get-amenities-data', [CustomFieldController::class, 'GetAmenitiesData']);
Route::post('get-custom-filded-list', [CustomFieldController::class, 'GetCustomFields']);
Route::middleware('admin.token')->post('update-custom-field/{id}', [CustomFieldController::class, 'updateCustomField']);
Route::post('custom-fields/search-and-filter', [CustomFieldController::class, 'searchAndFilter']);
Route::post('custom-fields/delete-custom-field', [CustomFieldController::class, 'deleteCustomField']);
Route::post('slug-uniqueness-check', [CustomFieldController::class, 'slugUniquesCheck']);
Route::get('get-model-condition-record', [CustomFieldController::class, 'getAllModelConditionRecords']);
Route::middleware('allrole.token')->get('get-custom-field-model-multi-condition', [CustomFieldController::class, 'getCustomFieldModelMultiCondition']);
Route::post('custom-field-listing-by-model-conditionid', [CustomFieldController::class, 'customFieldListingByModelConditionId']);




// custom field will end from here

// Group Route will start from here
Route::middleware('admin.token')->post('groups-create', [GroupController::class, 'createGroup']);
Route::middleware('admin.token')->post('groups-update/{id}', [GroupController::class, 'updateGroup']);
Route::middleware('admin.token')->post('groups-list', [GroupController::class, 'index']);
Route::middleware('admin.token')->post('groups-delete/{id}', [GroupController::class, 'deleteGroup']);
Route::get('/check-unique-group-name', [GroupController::class, 'checkUniqueGroupName']);

// Group Route will end from here

// Permission Route will start from here
Route::post('permissions-delete', [PermissionController::class, 'deletePermission']);
Route::get('permissions-listing', [PermissionController::class, 'index']);
Route::post('permissions/assign', [PermissionController::class, 'assignPermission']);
Route::post('role/assign', [Rolecontroller::class, 'assignRole']);
Route::post('remove/permission', [PermissionController::class, 'removePermission']);
Route::get('/role/{roleId}/permissions', [PermissionController::class, 'getPermissionsByRole']);

Route::post('assign-permissions', [PermissionController::class, 'assignDynamicPermissions']);
Route::get('/permissions/{role_id}', [PermissionController::class, 'getPermissionsByRole']);
Route::get('/model-names', [PermissionController::class, 'getModelNames']);
// Permission Route will end from here



// Ticket Route will start from here
Route::middleware('allrole.token')->post('tickets-create', [TicketController::class, 'store']);
Route::middleware('allrole.token')->get('tickets-list', [TicketController::class, 'index']);
Route::middleware('allrole.token')->post('tickets-update', [TicketController::class, 'update']);
Route::middleware('allrole.token')->post('tickets-delete', [TicketController::class, 'destroy']);
Route::middleware('allrole.token')->post('get-tickets-byuserid', [TicketController::class, 'show']);
Route::middleware('allrole.token')->post('update-tickets-status', [TicketController::class, 'updateTicketStatus']);

Route::middleware('admin.token')->post('tickets-status-create', [ticketstatuscontroller::class, 'store']);  //Done By softtonia
Route::middleware('admin.token')->post('tickets-status-update', [ticketstatuscontroller::class, 'update']); //Done By softtonia
Route::get('tickets-status-list', [ticketstatuscontroller::class, 'index']);
Route::middleware('admin.token')->post('tickets-status-delete', [ticketstatuscontroller::class, 'destroy']); //Done By softtonia
Route::middleware('admin.token')->post('get-tickets-status-byid', [ticketstatuscontroller::class, 'show']); //Done By softtonia

Route::middleware('admin.token')->post('tickets-department-create', [TicketDepartmentController::class, 'store']);  //Done By softtonia
Route::middleware('admin.token')->post('tickets-department-update', [TicketDepartmentController::class, 'update']); //Done By softtonia
Route::get('tickets-department-list', [TicketDepartmentController::class, 'index']);
Route::middleware('admin.token')->post('tickets-department-delete', [TicketDepartmentController::class, 'destroy']); //Done By softtonia
Route::middleware('admin.token')->post('get-tickets-department-byid', [TicketDepartmentController::class, 'show']); //Done By softtonia

Route::middleware('admin.token')->post('tickets-priority-create', [ticketprioritycontroller::class, 'store']); //Done By softtonia
Route::middleware('admin.token')->post('tickets-priority-update', [ticketprioritycontroller::class, 'update']); //Done By softtonia
Route::middleware('admin.token')->get('tickets-priority-list', [ticketprioritycontroller::class, 'index']); //Done By softtonia
Route::post('tickets-priority-delete', [ticketprioritycontroller::class, 'destroy']); //Done By softtonia
Route::middleware('admin.token')->post('get-tickets-priority-byid', [ticketprioritycontroller::class, 'show']); //Done By softtonia

Route::post('tickets-type-create', [TicketTypeController::class, 'store']);
Route::post('tickets-type-update', [TicketTypeController::class, 'update']);
Route::get('tickets-type-list', [TicketTypeController::class, 'index']);
Route::post('tickets-type-delete', [TicketTypeController::class, 'destroy']);
Route::post('get-tickets-type-byid', [TicketTypeController::class, 'show']);

Route::middleware('allrole.token')->post('tickets/respond', [TicketController::class, 'respond']);
Route::post('tickets-respond-list', [TicketController::class, 'respondlist']);
// Ticket Route will end from here

// Agent Route will start from here
Route::post('agent-store', [AgentController::class, 'store']);
Route::post('agent-update', [AgentController::class, 'update']);
Route::post('agent', [AgentController::class, 'destroy']);
Route::post('agents/toggle-status', [AgentController::class, 'toggleStatus']);
Route::middleware('consultancy.role')->post('send-request-by-consultancy-to-agent', [AgentController::class, 'sendRequestByConsultancyToAgent']);
Route::post('accept-decline-request-by-consultancy-to-agent', [AgentController::class, 'AcceptDeclineRequestByConsultancyToAgent']);
Route::post('leave-the-consultancy', [AgentController::class, 'leaveTheConsultancy']);
Route::post('get-agent-details', [AgentController::class, 'getAgentDetails']);
Route::get('get-all-join-request-listing', [AgentController::class, 'getAllJoinRequestList']);
Route::get('get-consultancy-details', [AgentController::class, 'getConsultancyDetails']);
Route::post('create-agent', [UserController::class, 'createAgent']);
Route::get('get-consultancy-agent-listing', [AgentController::class, 'getConsultancyAgentListing']);
Route::post('search-agent-by-id', [AgentController::class, 'searchAgentByID']);

// consultancy to company routes
Route::post('assign-project-to-consultancy-by-company', [UserController::class, 'assignProjectToConsultancyByCompany']);


// Agent Route will end from here

// Media Route will start from here
Route::post('media/add', [MediaController::class, 'addMedia']);
Route::post('media/update', [MediaController::class, 'updateMedia']);
Route::post('media', [MediaController::class, 'deleteMedia']);
Route::get('media-list', [MediaController::class, 'index']);
// Media Route will end from here


// =========Page=======
// =========privacy-policy========
Route::post('privacy-policy-update', [privacypolicycontroller::class, 'update']);
Route::get('privacy-policy-list', [privacypolicycontroller::class, 'index']);

// =========terms & condition========
Route::post('terms-and-condition-update', [termsandconditioncontroller::class, 'update']);
Route::get('terms-and-condition-list', [termsandconditioncontroller::class, 'index']);

// =========About us========
Route::post('aboutus-update', [AboutusController::class, 'update']);
Route::get('aboutus-list', [AboutusController::class, 'index']);

// =========Career========
Route::post('career-update', [CareerController::class, 'update']);
Route::get('career-list', [CareerController::class, 'index']);

// =========Legal========
Route::post('legal-update', [LegalController::class, 'update']);
Route::get('legal-list', [LegalController::class, 'index']);

// =========Sales Refund========
Route::post('sales-and-refund-update', [SalesRefundController::class, 'update']);
Route::get('sales-and-refund-list', [SalesRefundController::class, 'index']);

// =========Property Valuation========
Route::post('property-valuation-update', [PropertyValuationController::class, 'update']);
Route::get('property-valuation-list', [PropertyValuationController::class, 'index']);


// =========Help Cat========
Route::get('help-category-list', [HelpCategoryController::class, 'index']);
Route::middleware('admin.token')->post('help-category-create', [HelpCategoryController::class, 'store']);
Route::middleware('admin.token')->post('help-category-update', [HelpCategoryController::class, 'update']);
Route::middleware('admin.token')->post('help-category-delete', [HelpCategoryController::class, 'delete']);
Route::get('get-help-category-by-id/{id}', [HelpCategoryController::class, 'getdatabyId']);


// ==========Help Subcat=======
Route::get('help-subcategory-list', [HelpSubcategoryController::class, 'index']);
Route::middleware('admin.token')->post('help-subcategory-create', [HelpSubcategoryController::class, 'store']);
Route::middleware('admin.token')->post('help-subcategory-update', [HelpSubcategoryController::class, 'update']);
Route::middleware('admin.token')->post('help-subcategory-delete', [HelpSubcategoryController::class, 'delete']);
Route::get('get-help-subcategory-by-id/{id}', [HelpSubcategoryController::class, 'getdatabyId']);
Route::post('help-subcategory-by-categoryid', [HelpSubcategoryController::class, 'getHelpSubcategoryByCategoryId']);

// ===========Help Childcat=======
Route::get('help-childcategory-list', [HelpChildcategoryController::class, 'index']);
Route::middleware('admin.token')->post('help-childcategory-create', [HelpChildcategoryController::class, 'store']);
Route::middleware('admin.token')->post('help-childcategory-update', [HelpChildcategoryController::class, 'update']);
Route::middleware('admin.token')->post('help-childcategory-delete', [HelpChildcategoryController::class, 'delete']);
Route::get('get-help-childcategory-by-id/{id}', [HelpChildcategoryController::class, 'getdatabyId']);
Route::post('help-childcategory-by-subcategoryid', [HelpChildcategoryController::class, 'getHelpChildcategoryBySubcategoryId']);


// =========Help Art=======
Route::get('help-article-list', [HelpArticleController::class, 'index']);
Route::post('help-article-create', [HelpArticleController::class, 'store']);
Route::post('help-article-update', [HelpArticleController::class, 'update']);
Route::post('help-article-delete', [HelpArticleController::class, 'delete']);
Route::get('get-help-article-by-id/{id}', [HelpArticleController::class, 'getdatabyId']);


// ==========Like/Dislike===============


Route::post('/help-activity', [HelpActivityController::class, 'manageActivity']);

// =========Services=======
Route::get('services-list', [servicescontroller::class, 'index']);
Route::post('services-create', [servicescontroller::class, 'store']);
Route::post('services-update', [servicescontroller::class, 'update']);
Route::post('services', [servicescontroller::class, 'delete']);

// =========Project=======
Route::middleware(['auth.api.token'])->group(function () {
    Route::get('projects-list', [Projectcontroller::class, 'index']);
    Route::post('get-projectdata-byid', [Projectcontroller::class, 'show']);
    Route::post('projects-create', [Projectcontroller::class, 'store']);
    Route::post('projects-update', [Projectcontroller::class, 'update']);
    Route::post('projects-delete', [Projectcontroller::class, 'destroy']);
});

// =========Builder=======
Route::get('builder-list', [Buildercontroller::class, 'index']);
Route::post('get-builderdata-byid', [Buildercontroller::class, 'show']);
Route::post('builder-create', [Buildercontroller::class, 'store']);
Route::post('builder-update', [Buildercontroller::class, 'update']);
Route::post('builder-delete', [Buildercontroller::class, 'destroy']);

// =========Profile=======
Route::post('complete-your-profile', [Profilecontroller::class, 'updateProfile']);
Route::post('approve-user', [Profilecontroller::class, 'approveuser']);

// =====For Client Review=====
Route::post('add-client-review', [ClientReviewController::class, 'store']);
Route::post('edit-client-review', [ClientReviewController::class, 'update']);
Route::post('delete-client-review', [ClientReviewController::class, 'destroy']);
Route::get('get-client-review', [ClientReviewController::class, 'index']);
Route::get('get-client-review-by-id/{id}', [ClientReviewController::class, 'getdatabyId']);

// =====For Faq Category=====
Route::middleware('admin.token')->post('add-faq-category', [FaqCategoryController::class, 'store']); //Done By softtonia
Route::middleware('admin.token')->post('edit-faq-category', [FaqCategoryController::class, 'update']); //Done By softtonia
Route::middleware('admin.token')->post('delete-faq-category', [FaqCategoryController::class, 'destroy']); //Done By softtonia
Route::get('get-faq-category', [FaqCategoryController::class, 'index']); //Done By softtonia
Route::middleware('admin.token')->get('get-faq-category-by-id/{id}', [FaqCategoryController::class, 'getdatabyId']); //Done By softtonia


// =====For Faq =======
Route::middleware('admin.token')->post('add-faq', [FaqController::class, 'store']); //Done By softtonia
Route::middleware('admin.token')->post('edit-faq', [FaqController::class, 'update']); //Done By softtonia
Route::middleware('admin.token')->post('delete-faq', [FaqController::class, 'destroy']); //Done By softtonia
Route::get('get-faq', [FaqController::class, 'index']); //Done By softtonia
Route::middleware('admin.token')->get('get-faq-by-id/{id}', [FaqController::class, 'getdatabyId']); //Done By softtonia

// Otp Route
Route::post('/verify-email-otp', [OtpController::class, 'emailVerifyOtp']);
Route::post('send-otp', [OtpController::class, 'sendOtp']);
Route::post('verify-otp', [OtpController::class, 'verifyOtp']);
Route::post('/verify-email-otp', [OtpController::class, 'emailVerifyOtp']);


// With otp password forget
Route::post('/generate-email-otp', [EmailOtpController::class, 'generateOtp']);
Route::post('/reset-password', [EmailOtpController::class, 'resetPassword']);

// Country, State, City Get

Route::get('countries', [LocationController::class, 'getCountries']);
Route::get('states/{countryId}', [LocationController::class, 'getStatesByCountry']);
Route::get('cities/{stateId}', [LocationController::class, 'getCitiesByState']);



Route::middleware('allrole.token')->post('business-role-update-profile', [UserController::class, 'updateProfile']);


Route::get('auth/google', [GoogleAuthController::class, 'redirectToGoogle']);
Route::get('auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback']);


//Un-used Route
// Route::get('list-custom-fields', [CustomFieldController::class, 'index']);
// Route::get('all-location-listing', [CustomFieldController::class, 'locationListing']);
// Route::get('get-all-developer-listing', [UserController::class, 'allDeveloperListing']);

// =======Website Developer Listing============
// Route::post('add-developer-listing-by-website', [DeveloperlistingController::class, 'storeWebsite']);
// Route::post('edit-developer-listing-by-website', [DeveloperlistingController::class, 'updateWebsite']);
// Route::post('delete-developer-listing-by-website', [DeveloperlistingController::class, 'destroyWebsite']);
// Route::get('fetch-all-developer-listing-by-website', [DeveloperlistingController::class, 'indexWebsite']);
// Route::get('get-data-developer-by-website/{id}', [DeveloperlistingController::class, 'getdatabyIdWebsite']);
// Route::get('multiple-custom-field-listing-by-model-conditionid', [CustomMultipleFieldController::class, 'customFieldListingByModelConditionId']);
// Route::get('get-details-byuserid-for-website', [UserController::class, 'getdetailsbyuseridForWebsite']);
// Route::any('reset-password', [UserController::class, 'resetPassword']);
// Route::get('/user/{id}', [UserController::class, 'getUserDetails']);
//Route::get('/get-data-by-token', [UserController::class, 'getDataByToken']);
// Route::get('get-all-agent-listing-by-admin', [UserController::class, 'allAgentListingByAdmin']);
// Route::get('get-agent-listing', [UserController::class, 'getAgentListing']);
