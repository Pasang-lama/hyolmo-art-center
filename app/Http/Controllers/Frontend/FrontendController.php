<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Notifications\VerifyEmail;
use Laravel\Socialite\Facades\Socialite;
use App\Models\Category,
    App\Models\Product,
    App\Models\ProductAttribute,
    App\Models\User,
    App\Models\Brand,
    App\Models\Banner,
    App\Models\ProductSizes,
    App\Models\Review,
    App\Models\AboutUs,
    App\Models\Blogs,
    App\Models\Video,
    App\Models\Testimonials,
    App\Models\Pages,
    App\Models\Homepageextra;
use Carbon\Carbon;
use Exception;
use Hash, DB, Notification, Cart, Mail;

class FrontendController extends Controller
{
    private $record_per_page;
    public function __construct()
    {
        $this->record_per_page = 15;
        $this->middleware(["XssSanitizer"]);
        $this->emailAddress = "pasanglama66666@gmail.com";
    }

    public function home()
    {
        $title = "Home";
        $banners = Banner::where(["status" => 1])
            ->orderBy("order", "ASC")
            ->get();

        $latest_products = Product::with("category")
            ->orderBy("id", "DESC")
            ->take(5)
            ->get();

        $sale_products = Product::with("category")
            ->where(["sale" => "1", "status" => "1"])
            ->take(5)
            ->get();

        $trending_products = Product::with("category")
            ->orderBy("view_count", "DESC")
            ->take(9)
            ->get();

        $aboutus = AboutUs::first();
        $blogs = Blogs::where(["blog_status" => 1])
            ->orderBy("created_at", "DESC")
            ->limit(3)
            ->get();
        $video = Video::first();
        $testimonials = Testimonials::orderBy("testimonial_order", "ASC")
            ->limit(10)
            ->get();

        $featuredCategories = Category::where([
            "categories.status" => 1,
            "categories.featured_category" => 1,
        ])
            ->orderBy("categories.order", "DESC")
            ->limit(5)
            ->get();
        $homepageextras = Homepageextra::first();

        return view(
            "frontend/pages/home",
            compact(
                "latest_products",
                "sale_products",
                "trending_products",
                "banners",
                "aboutus",
                "blogs",
                "video",
                "testimonials",
                "featuredCategories",
                "homepageextras",
                "title"
            )
        );
    }


    public function single_category($slug)
    {
        $cat_with_subcat_arr = [];
        $category = Category::where("slug", $slug)
            ->with("categories")
            ->firstOrFail();
        $title = $category->category_name;
        array_push($cat_with_subcat_arr, $category->id);
        $category->childrenCategoriesIds($cat_with_subcat_arr);

        $products = DB::table("products")
            ->join(
                "product_categories",
                "product_categories.product_id",
                "products.id"
            )
            ->select("products.*", "product_categories.product_id as p_id")
            ->whereIn("product_categories.category_id", $cat_with_subcat_arr)
            ->distinct()
            ->paginate($this->record_per_page);

        $brands = Brand::get();
        $group_slug = "";
        $group_name = "";
        $category_name = $category;
        return view(
            "frontend/pages/allproducts",
            compact(
                "title",
                "category",
                "products",
                "brands",
                "group_slug",
                "group_name",
                "category_name"
            )
        );
    }

    public function main_product(Request $request, $slug)
    {
        $product = Product::getProductBySlug($slug);
        if ($product != null) {
            $product_images = ProductAttribute::select(
                "product_variation_image"
            )
                ->where(["product_id" => $product->id])
                ->get();
            $product_sizes = ProductSizes::select("size", "price", "stock")
                ->where(["product_id" => $product->id])
                ->get();

            if ($request->session()->exists($slug)) {
                if (time() - $request->session()->get($slug) > 1200) {
                    $request->session()->forget($slug);
                    $request->session()->put($slug, time());
                    $product->increment("view_count");
                }
            } else {
                $request->session()->put($slug, time());
                $product->increment("view_count");
            }

            $cross_selling_products = new Product();

            $related_products = Product::getRelatedProducts($slug);

            $average_ratings = Review::Where([
                "product_id" => $product->id,
            ])
                ->pluck("rating")
                ->avg();

            $product_average =
                $average_ratings > 0 ? round($average_ratings) : 0;

            $title = $product->product_name;
            if (Auth::user() == null) {
                $id = 0;
            } else {
                $id = Auth::user()->id;
            }
            $wishlistItems = Cart::instance("wishlist_" . $id)->content();
            if ($wishlistItems->isNotEmpty()) {
                foreach ($wishlistItems as $items) {
                    if ($items->model->slug == $slug) {
                        $itemsAvailableInWishList = 1;
                        break;
                    } else {
                        $itemsAvailableInWishList = 0;
                    }
                }
            } else {
                $itemsAvailableInWishList = 0;
            }

            $bodyClass = "main_product";
            return view(
                "frontend/pages/main_product",
                compact(
                    "product",
                    "product_images",
                    "product_sizes",
                    "cross_selling_products",
                    "title",
                    "bodyClass",
                    "related_products",
                    "product_average",
                    "itemsAvailableInWishList"
                )
            );
        } else {
            abort(404);
        }
    }

    public function products($slug)
    {
        if ($slug == "new_arrivals") {
            $products = Product::orderBy("id", "DESC");
        } elseif ($slug == "sale") {
            $products = Product::where("sale", "1")
                ->orderBy("id", "desc")
                ->paginate(20);
        } elseif ($slug == "trending") {
            $products = Product::orderBy("view_count", "DESC");
        } else {
            $products = Product::orderBy("id", "DESC");
        }
        return view("frontend/pages/products", compact("products", "slug"));
    }

    public function login()
    {
        return view("frontend/pages/login");
    }

    public function loginSubmit(Request $request)
    {
        $request->validate(
            [
                "email" => "required|email",
                "password" => "required",
            ],
            [
                "email.required" => "Email is required",
                "email.email" => "Email is not valid email",
                "password.required" => "Password is required",
            ]
        );

        $data = $request->all();
        if (
            Auth::attempt(
                [
                    "email" => $data["email"],
                    "password" => $data["password"],
                    "status" => "active",
                    "role" => "user",
                ],
                $request->remember
            )
        ) {
            Session::put("user", $data["email"]);
            $cartItemCount = Cart::instance("" . auth()->user()->id)->content()->count();
            $wishlistItemCount = Cart::instance("wishlist_" . auth()->user()->id)->content()->count();

            return response()->json([
                "success" => "User loggged in successfully",
                "redirect_url" => "",
                "cartItemCount" => $cartItemCount,
                "wishlistItemCount" => $wishlistItemCount
            ]);

            if (!empty($data["cart_data"])) {
                return response()->json([
                    "success" =>
                    "User loggged in successfully.Taking you to cart page",
                    "redirect_url" => route("checkout.index"),
                ]);
            } else {
                return response()->json([
                    "success" => "User loggged in successfully",
                    "redirect_url" => "",
                ]);
            }
        } elseif (
            User::where([
                "email" => $data["email"],
                "status" => "inactive",
            ])->first()
        ) {
            Session::put("user", $data["email"]);
            return response()->json([
                "error" => "User account is not activated",
                "redirect_url" => "",
            ]);
        } else {
            return response()->json([
                "error" => "Invalid email and password, please try again",
                "redirect_url" => "",
            ]);
        }
    }

    public function register()
    {
        return view("frontend/pages/register");
    }

    public function registerSubmit(Request $request)
    {
        // try {
        $this->validate(
            $request,
            [
                "name" => "required",
                "email" => "required|email|unique:users,email",
                "password" => "required|min:8|same:confirmpassword",
                "confirmpassword" => "required|min:8",
                "terms_condition" => "required",
            ],
            [
                "name.required" => "Name is required",
                "email.required" => "Email is required",
                "email.email" => "Email must be valid email",
                "email.unique" =>
                "Email is already in use. Please use another one",
                "password.required" => "Password is required",
                "password.same" => "Password mismatched with confirm password",
                "password.min" => "Password must be 8 characters minimum",
                "confirmpassword.required" => "Confirm Password is required",
                "confirmpassword.min" =>
                "Password must be 8 characters minimum",
                "terms_condition.required" =>
                "Terms and conditions is required",
            ]
        );

        $data = $request->all();
        $data["password"] = Hash::make($data["password"]);
        $data["role"] = "user";
        $check = User::create($data);
        if ($check) {
            Session::put("user", $data["email"]);
            $userdata = User::find($check->id);
            $dataactive = [
                "status" => "active",
            ];

            $userdata->notify(new VerifyEmail($userdata, $dataactive));

            return response()->json([
                "success" =>
                "User registered successfully. Please verfiy your account",
                "redirect_url" => route("user.verify"),
            ]);
        } else {
            return response()->json([
                "error" => "User not registered. Please try again",
                "redirect_url" => "",
            ]);
        }
        // } catch (\Exception $e) {
        //     return response()->json([
        //         "error" => "User not registered. Please try again",
        //         "redirect_url" => "",
        //     ]);
        // }
    }

    public function verify()
    {
        return view("frontend.customer.auth.verify");
    }

    public function verify_email($slug)
    {
        $user = User::where("email", $slug)->firstOrfail();
        if ($user) {
            $user->status = "active";
        }
        $user->save();
        if ($user) {
            auth()->login($user);
            Session::put("user", $slug);
            request()
                ->session()
                ->flash("success_msg", "Successfully registered");
        }
        return redirect()->route("home");
    }

    public function resend_email($slug)
    {
        $userdata = User::where("email", $slug)->firstorFail();
        $dataactive = [
            "status" => "active",
        ];
        $userdata->notify(new VerifyEmail($userdata, $dataactive));
        return redirect()
            ->back()
            ->with("success_msg", "Email Send!");
    }

    public function redirectTOGoogle()
    {
        return Socialite::driver("google")->redirect();
    }

    public function handleGoogleCallback()
    {
        $user = Socialite::driver("google")->user();
        $provider = "google";
        $this->_registerOrLoginUser($user, $provider);
        return redirect()->route("home");
    }

    public function redirectTOFacebook()
    {
        return Socialite::driver("facebook")->redirect();
    }
    public function handleFacebookCallback()
    {
        $user = Socialite::driver("facebook")->user();
        $provider = "facebook";
        $this->_registerOrLoginUser($user, $provider);
        return redirect()->route("home");
    }

    protected function _registerOrLoginUser($data, $provider)
    {
        $user = User::where("email", "=", $data->email)->first();
        if (!$user) {
            $user = new User();
            $user->name = $data->name;
            $user->email = $data->email;
            $user->provider_id = $data->id;
            $user->provider = $provider;
            $user->status = "active";

            //  if($data->avatar) {
            //   $user->photo= $this->image_upload($data, "avatar");
            //  }

            $user->save();
        }
        Auth::login($user);
        Session::put("user", $data["email"]);
    }

    public function aboutus(Request $request)
    {
        $title = "About Us";
        $aboutus = AboutUs::first();
        return view("frontend.pages.aboutus", compact("title", "aboutus"));
    }

    public function contact(Request $request)
    {
        $title = "Contact Us";
        return view("frontend.pages.contact", compact("title"));
    }
    public function contact_details(Request $request)
    {
        $this->validate(
            $request,
            [
                "name" => "required",
                "email" => "required|email",
                "contact" => "required|digits:10|numeric",
            ],
            [
                "name.required" => "Name is required",
                "email.required" => "Email is required",
                "email.email" => "Email must be valid email",
                "contact.required" => "Phone is required",
                "contact.digits" => "Phone must be 10 numbers",
                "contact.numeric" => "Phone must be number",
            ]
        );
        try {
            $data = [
                "name" => $request->name,
                "email" => $request->email,
                "contact" => $request->contact,
                "message" => $request->message,
            ];
            Mail::to(env("MAIL_FROM_ADDRESS"))->send(
                new \App\Mail\ContactMailable($data)
            );
            if ($request->ajax()) {
                return response()->json(["success" => "Contact message sent sucessfully"]);
            } else {
                return redirect()
                    ->back()
                    ->with("success_msg", "Contact message sent sucessfully");
            }
        } catch (\Exception $e) {
            if ($request->ajax()) {
                return response()->json(["error" => "Contact message cannotnot sent. Please try again, $e"]);
            } else {
                return redirect()->back()->with(
                    "error_msg",
                    "Contact message cannotnot sent. Please try again ,  $e"
                );
            }
        }
    }
    public function all_products(Request $request)
    {
        $title = "Products";
        $products = Product::where(["status" => 1])
            ->orderBy("id", "DESC")
            ->paginate($this->record_per_page);
        return view("frontend.pages.products", compact("title", "products"));
    }

    public function productsSuitableFor(Request $request, $suitable_for)
    {
        $title = "Products";
        $group_id = getGroupIdFromSlug($suitable_for);
        $group_name = getGroupNameFromSlug($suitable_for);
        $group_slug = "";
        $products = Product::where([
            "status" => 1,
            "suitable_for" => $group_id,
        ])
            ->orderBy("id", "DESC")
            ->paginate($this->record_per_page);
        return view(
            "frontend.pages.allproducts",
            compact("title", "products", "group_name", "group_slug")
        );
    }

    public function productsSuitableForWithCategory(
        Request $request,
        $suitable_for,
        $category
    ) {
        $title = "Products";
        $group_id = getGroupIdFromSlug($suitable_for);
        $group_name = getGroupNameFromSlug($suitable_for);
        $category_name = Category::select("category_name")
            ->where(["slug" => $category])
            ->first();
        $group_slug = $suitable_for;
        $products = Product::with("category")
            ->whereHas("category", function ($q) use ($category) {
                $q->where("categories.slug", $category);
            })
            ->where([
                "products.status" => 1,
                "products.suitable_for" => $group_id,
            ])
            ->orderBy("id", "DESC")
            ->paginate($this->record_per_page);
        return view(
            "frontend.pages.allproducts",
            compact(
                "title",
                "products",
                "group_name",
                "category_name",
                "group_slug"
            )
        );
    }

    public function getForm(Request $request)
    {
        $form_type = $request->get("type");
        if ($form_type == "loginform") {
            $html = view("frontend.pages.login")->render();
            $p_text = "Login with <span>social account</span>";
        } else {
            $html = view("frontend.pages.register")->render();
            $p_text = "Register with <span>social account</span>";
        }
        return response()->json([
            "form_html" => $html,
            "p_text" => $p_text,
        ]);
    }

    public function blogs(Request $request)
    {
        $blogs = Blogs::where([
            "blog_status" => 1,
        ])->paginate($this->record_per_page);

        $title = "Blogs";
        return view("frontend.pages.blogs", compact("title", "blogs"));
    }

    public function blog_details(Request $request, $slug = null)
    {
        $blog_details = Blogs::where([
            "blog_slug" => $slug,
            "blog_status" => 1,
        ])->first();
        if (!empty($blog_details)) {
            $title = $blog_details->blog_title;

            $blogs = Blogs::where("blog_status", 1)
                ->whereNotIn("blog_slug", [$slug])
                ->limit(5)
                ->orderBy("id", "DESC")
                ->get();

            return view(
                "frontend.pages.blog_details",
                compact("title", "blog_details", "blogs")
            );
        } else {
            abort(404);
        }
    }

    public function pages_details(Request $request, $page_slug)
    {
        $page_details = Pages::where(["page_slug" => $page_slug])->first();
        if (!empty($page_details)) {
            $title = $page_details->page_title;
            return view(
                "frontend.pages.page_details",
                compact("title", "page_details")
            );
        } else {
            abort(404);
        }
    }

    public function brands(Request $request)
    {
        $title = "Brands";
        $brands = Brand::orderBy("order", "ASC")->paginate(
            $this->record_per_page
        );
        return view("frontend.pages.brands", compact("title", "brands"));
    }

    public function brand_details(Request $request, $brand_slug = null)
    {
        $brandDetails = Brand::where(["slug" => $brand_slug])->firstorFail();
        if ($brandDetails) {
            $title = $brandDetails->name;
            $brandProducts = Brand::join(
                "products",
                "products.brand_id",
                "=",
                "brands.id"
            )
                ->where(["brands.slug" => $brand_slug, "products.status" => 1])
                ->orderBy("products.created_at", "DESC")
                ->select(["products.*"])
                ->paginate($this->record_per_page);

            return view(
                "frontend.pages.brand_products",
                compact("title", "brandDetails", "brandProducts")
            );
        }
    }
    public function forgotPasswordIndex()
    {
        return view("frontend.pages.forgotPassword");
    }
    public function emailVerify(Request $request)
    {
        $request->validate(
            [
                "email" => "required|email",
            ],
            [
                "email.required" => "Email is required",
                "email.email" => "Email is not valid email",
            ]
        );
        try {
            $userEmail = User::where([
                'email' => $request->email,
                'role' => 'user'
            ])->first();

            $expirytime = Carbon::now(new \DateTimeZone('Asia/Kathmandu'))->addMinutes(30)->timestamp;

            $expiry_url = route('user.password.reset', [
                    "email" => urlencode($userEmail->email),
                    "expiry_time" =>  $expirytime
            ]);

            $data = [
                'email' => $userEmail->email,
                'expiry_url' => $expiry_url
            ];

            Mail::to($request->email)->send(
                new \App\Mail\EmailVerifytMailable($data)
            );
            return  redirect()->back()->with("success_msg", "Password reset link sent successfully");
        } catch (\Exception $e) {
            return  redirect()->back()->with("error_msg", "Email not found, Try again");
        }
    }
    public function emailSubmit(Request $request)
    {
        $email = urldecode($request->get('email'));
        $expiry_timestamp = urldecode($request->get('expiry_time'));
        if(strtotime($expiry_timestamp) > strtotime("-30 minutes")) {
            return view("frontend.pages.password_expired");
        } else {
            return view("frontend.pages.changePassword", compact('email'));
        }
    }
    public function changePasswordSubmit(Request $request, $email)
    {
        $request->validate(
            [
                'password' => 'required|min:6',
                "password_confirmation" => 'required|min:6'
            ]
        );
        if ($request->password == $request->password_confirmation) {
            $userPassword = User::where('email', $email)->firstOrFail();
            $password['password'] = Hash::make($request->password);
            $userPassword->update($password);
            return  redirect()->back()->with("success_msg", "Password reset successfully");
        } else {
            return redirect()
                ->back()
                ->with("error_msg", "Password and conform  password did  not match try again ");
        }
    }
}
