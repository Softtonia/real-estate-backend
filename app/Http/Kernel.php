<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,

        \App\Http\Middleware\LogAndBlockIpMiddleware::class,
        \App\Http\Middleware\DynamicApiCors::class,

    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,

            // ✅ Your custom middleware
            // \App\Http\Middleware\ValidateApiClient::class,

        ],

    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive' => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed' => \App\Http\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'admin' => \App\Http\Middleware\AdminMiddleware::class,
        'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        'admin_or_consultancy' => \App\Http\Middleware\AdminOrConsultancyMiddleware::class,

    ];

    protected $routeMiddleware = [
        // Other middleware...
        // 'auth.api.token' => \App\Http\Middleware\CompanyApiToken::class,
        'api.token' => \App\Http\Middleware\ApiTokenMiddleware::class,
        'admin.token' => \App\Http\Middleware\AdminTokenMiddleware::class,
        'token.expiration' => \App\Http\Middleware\CheckTokenExpiration::class,
        'allrole.token' => \App\Http\Middleware\AllRolesMiddleware::class,
        'allow.admin_company' => \App\Http\Middleware\AdminCompanyMiddleware::class,
        'allow.admin_developer' => \App\Http\Middleware\AdminDeveloperMiddleware::class,
        'consultancy.role' => \App\Http\Middleware\ConsultancyRoleMiddleware::class,
        'excludeOwner' => \App\Http\Middleware\ExcludeOwnerMiddleware::class,
        'adminOrCurrentUser' => \App\Http\Middleware\AdminCurrentUser::class,
        'allow.owner.agent' => \App\Http\Middleware\AllowOwnerAndAgent::class,

        'allow.property.listing' => \App\Http\Middleware\AllowPropertyListing::class,
        'allow.owner.role' => \App\Http\Middleware\AllowOwnerRole::class,

        'validate.api.client' => \App\Http\Middleware\ValidateApiClient::class,

        'OnlyCompany' => \App\Http\Middleware\OnlyCompanyMiddleware::class,

        'app.blocked_ip' => \App\Http\Middleware\BlockSuspiciousApiIp::class,
        'app.password' => \App\Http\Middleware\VerifyApplicationPassword::class,
        'app.origin' => \App\Http\Middleware\VerifyAllowedOrigin::class,
        'app.signature' => \App\Http\Middleware\VerifyRequestSignature::class,
        'app.rate' => \App\Http\Middleware\ApiClientRateLimit::class,
        'app.log' => \App\Http\Middleware\LogApiRequest::class,
        'client.permission' => \App\Http\Middleware\CheckClientPermission::class,



    ];
}
