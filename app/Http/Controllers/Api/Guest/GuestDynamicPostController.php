<?php

namespace App\Http\Controllers\Api\Guest;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guest\GuestDynamicPostListRequest;
use App\Http\Resources\Guest\GuestDynamicPostCardResource;
use App\Http\Resources\Guest\GuestDynamicPostDetailResource;
use App\Services\Frontend\GuestDynamicPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class GuestDynamicPostController extends Controller
{
    public function __construct(
        private GuestDynamicPostService $service
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Guest Listing
    |--------------------------------------------------------------------------
    |
    | GET /api/guest/posts/property-listing
    | GET /api/guest/posts/property-listing?featured=1
    |
    */

    public function index(
        GuestDynamicPostListRequest $request,
        string $postType
    ): JsonResponse {
        try {
            $validated =
                $request->validated();

            $result =
                $this->service
                    ->paginate(
                        postTypeSlug:
                            $postType,

                        filters:
                            $validated
                    );

            $paginator =
                $result['paginator'];

            $postTypeModel =
                $result['post_type'];

            $featuredOnly =
                (bool) $result[
                    'featured_only'
                ];

            $items =
                GuestDynamicPostCardResource::collection(
                    $paginator
                        ->getCollection()
                )->resolve(
                    $request
                );

            return response()->json([
                'status' => true,

                'message' =>
                    $featuredOnly
                        ? 'Featured posts fetched successfully.'
                        : 'Posts fetched successfully.',

                'data' => [
                    /*
                    |--------------------------------------------------------------------------
                    | Post Type
                    |--------------------------------------------------------------------------
                    */

                    'post_type' => [
                        'id' =>
                            (int) $postTypeModel->id,

                        'name' =>
                            $postTypeModel->name,

                        'slug' =>
                            $postTypeModel->slug,
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Applied Filters
                    |--------------------------------------------------------------------------
                    */

                    'filters' => [
                        'featured' =>
                            $featuredOnly,

                        'country_id' =>
                            isset($validated['country_id']) ? (int) $validated['country_id'] : null,

                        'state_id' =>
                            isset($validated['state_id']) ? (int) $validated['state_id'] : null,

                        'city_id' =>
                            isset($validated['city_id']) ? (int) $validated['city_id'] : null,

                        'area_locality' =>
                            $validated['area_locality'] ?? null,

                        'search' =>
                            $validated['search']
                            ?? null,

                        'sort_by' =>
                            $validated['sort_by']
                            ?? 'latest',
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Results
                    |--------------------------------------------------------------------------
                    */

                    'items' =>
                        $items,

                    /*
                    |--------------------------------------------------------------------------
                    | Pagination
                    |--------------------------------------------------------------------------
                    */

                    'pagination' => [
                        'current_page' =>
                            $paginator
                                ->currentPage(),

                        'per_page' =>
                            $paginator
                                ->perPage(),

                        'total' =>
                            $paginator
                                ->total(),

                        'last_page' =>
                            $paginator
                                ->lastPage(),

                        'from' =>
                            $paginator
                                ->firstItem(),

                        'to' =>
                            $paginator
                                ->lastItem(),

                        'has_more_pages' =>
                            $paginator
                                ->hasMorePages(),
                    ],
                ],
            ]);
        } catch (
            HttpExceptionInterface $e
        ) {
            return response()->json([
                'status' => false,

                'message' =>
                    $e->getMessage()
                    ?: 'Resource not found.',

                'error' =>
                    null,
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            /*
             * Do not expose internal SQL / filesystem /
             * application errors to guest users.
             */
            Log::error(
                'Guest DynamicPost listing failed.',
                [
                    'post_type' =>
                        $postType,

                    'error' =>
                        $e->getMessage(),

                    'exception' =>
                        get_class($e),
                ]
            );

            return response()->json([
                'status' => false,

                'message' =>
                    'Unable to fetch posts.',

                'error' =>
                    'Internal server error.',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Guest Detail
    |--------------------------------------------------------------------------
    |
    | GET /api/guest/posts/property-listing/15
    | GET /api/guest/posts/project-listing/25
    | GET /api/guest/posts/developer-listing/30
    |
    */

    public function show(
        Request $request,
        string $postType,
        int $dynamicPostId
    ): JsonResponse {
        try {
            if ($dynamicPostId <= 0) {
                return response()->json([
                    'status' => false,

                    'message' =>
                        'Invalid DynamicPost ID.',

                    'error' =>
                        'Invalid DynamicPost ID.',
                ], 422);
            }

            $result =
                $this->service
                    ->detail(
                        postTypeSlug:
                            $postType,

                        dynamicPostId:
                            $dynamicPostId
                    );

            $post =
                $result['post'];

            $template =
                $result['template'];

            return response()->json([
                'status' => true,

                'message' =>
                    'Post details fetched successfully.',

                'data' => [
                    /*
                    |--------------------------------------------------------------------------
                    | Complete DynamicPost
                    |--------------------------------------------------------------------------
                    */

                    'post' =>
                        (
                            new GuestDynamicPostDetailResource(
                                $post
                            )
                        )->resolve(
                            $request
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | Template Builder
                    |--------------------------------------------------------------------------
                    |
                    | Exact response produced by the project's existing
                    | TemplateResolveService.
                    |
                    */

                    'template' =>
                        $template,

                    'has_template' =>
                        $template !== null,
                ],
            ]);
        } catch (
            HttpExceptionInterface $e
        ) {
            return response()->json([
                'status' => false,

                'message' =>
                    $e->getMessage()
                    ?: 'Post not found.',

                'error' =>
                    null,
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            Log::error(
                'Guest DynamicPost detail failed.',
                [
                    'post_type' =>
                        $postType,

                    'dynamic_post_id' =>
                        $dynamicPostId,

                    'error' =>
                        $e->getMessage(),

                    'exception' =>
                        get_class($e),
                ]
            );

            return response()->json([
                'status' => false,

                'message' =>
                    'Unable to fetch post details.',

                'error' =>
                    'Internal server error.',
            ], 500);
        }
    }
}