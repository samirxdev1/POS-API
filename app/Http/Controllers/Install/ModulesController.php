<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Module;
use ZipArchive;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class ModulesController extends Controller
{
    protected $moduleUtil;

    /**
     * Constructor
     *
     * @param  ModuleUtil  $moduleUtil
     * @return void
     */
    public function __construct(ModuleUtil $moduleUtil)
    {
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        //Get list of all modules.
        $modules = Module::toCollection()->toArray();
        $store_modules = $this->__available_modules();

        foreach ($modules as $module => $details) {
            $modules[$module]['is_installed'] = $this->moduleUtil->isModuleInstalled($details['name']) ? true : false;

            //Fall back to the store description when module.json has none.
            if (empty($details['description']) && isset($store_modules[$details['name']])) {
                $modules[$module]['description'] = $store_modules[$details['name']]['description'];
            }

            //Get version information.
            if ($modules[$module]['is_installed']) {
                $modules[$module]['version'] = $this->moduleUtil->getModuleVersionInfo($details['name']);
            }

            //Install Link.
            try {
                $modules[$module]['install_link'] = action('\Modules\\'.$details['name'].'\Http\Controllers\InstallController@index');
            } catch (\Exception $e) {
                $modules[$module]['install_link'] = '#';
            }

            //Update Link.
            try {
                $modules[$module]['update_link'] = action('\Modules\\'.$details['name'].'\Http\Controllers\InstallController@update');
            } catch (\Exception $e) {
                $modules[$module]['update_link'] = '#';
            }

            //Uninstall Link.
            try {
                $modules[$module]['uninstall_link'] = action('\Modules\\'.$details['name'].'\Http\Controllers\InstallController@uninstall');
            } catch (\Exception $e) {
                $modules[$module]['uninstall_link'] = '#';
            }
        }

        $is_demo = (config('app.env') == 'demo');

        //Modules sold by us that are not on this system yet.
        $buy_modules = array_diff_key($store_modules, $modules);

        $max_upload_size = $this->__maxUploadSize();

        return view('install.modules.index')
            ->with(compact('modules', 'is_demo', 'buy_modules', 'max_upload_size'));

        //Option to uninstall

        //Option to activate/deactivate

        //Upload module.
    }

    public function regenerate()
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        try {
            Artisan::call('module:publish');
            Artisan::call('passport:install --force');
            // Artisan::call('scribe:generate');

            $output = ['success' => 1,
                'msg' => __('lang_v1.success'),
            ];
        } catch (Exception $e) {
            $output = ['success' => 1,
                'msg' => $e->getMessage(),
            ];
        }

        return redirect()->back()->with('status', $output);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Activate/Deaactivate the specified module.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $module_name)
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        try {
            $module = Module::find($module_name);

            //php artisan module:disable Blog
            if ($request->action_type == 'activate') {
                $module->enable();
            } elseif ($request->action_type == 'deactivate') {
                $module->disable();
            }
            // Publish assets for this specific module after status change
            Artisan::call('module:publish', ['module' => $module_name, '--force' => true]);

            // Clear module assets cache when module is activated/deactivated
            Cache::forget('module_assets');

            $output = ['success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            $output = ['success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return redirect()->back()->with(['status' => $output]);
    }

    /**
     * Deletes the module.
     *
     * @param  string  $module_name
     * @return \Illuminate\Http\Response
     */
    public function destroy($module_name)
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        try {
            $module = Module::find($module_name);
            // $module->delete();

            $path = $module->getPath();

            // Clear module assets cache when module is deleted
            Cache::forget('module_assets');

            die("To delete the module delete this folder <br/>" . $path . '<br/> Go back after deleting');

            $output = ['success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            $output = ['success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return redirect()->back()->with(['status' => $output]);
    }

    /**
     * Upload the module.
     */
    public function uploadModule(Request $request)
    {
        if (! auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        try {
            $request->validate([
                'module' => 'required|file|mimes:zip|max:10240', // 10MB max
            ]);

            //get zipped file
            $module = $request->file('module');
            $module_name = Str::slug(str_replace('.zip', '', $module->getClientOriginalName()));

            //check if 'Modules' folder exist or not, if not exist create
            $path = '../Modules';
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }

            //extract the zipped file in given path
            $zip = new ZipArchive();
            if ($zip->open($module) === true) {
                $zip->extractTo($path.'/');
                $zip->close();

                // Check for required files after extraction
                $module_dir = $path . '/' . $module_name;
                $data_controller_path = $module_dir . '/Http/Controllers/DataController.php';
                if (!(file_exists($module_dir . '/composer.json')
                    && file_exists($module_dir . '/module.json')
                    && file_exists($module_dir . '/Config/config.php')
                    && file_exists($data_controller_path))
                ) {
                    \File::deleteDirectory($module_dir);
                    $output = ['success' => false,
                        'msg' => __('messages.something_went_wrong'),

                        // 
                    ];
                    return redirect()->back()->with(['status' => $output]);
                }

                // Clear module assets cache when new module is uploaded
                Cache::forget('module_assets');

                // Publish assets for the uploaded module using its name
                try {
                    Artisan::call('module:publish', ['module' => $module_name, '--force' => true]);
                } catch (\Throwable $e) {
                    // Fallback to publishing all if targeted signature not supported
                    Artisan::call('module:publish');
                }
            }

            $output = ['success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $output = ['success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->back()->with(['status' => $output]);
    }

    /**
     * Modules sold for UltimatePOS, keyed by module name.
     * Shown with a Buy link when not present, and used as the description
     * when a module's module.json has none.
     */
    private function __available_modules()
    {
        return [
            'Essentials' => ['name' => 'Essentials Module', 'url' => 'https://ultimatefosters.com/recommends/essential-app/', 'description' => 'Essentials features for every growing businesses.'],
            'Superadmin' => ['name' => 'Superadmin Module', 'url' => 'https://ultimatefosters.com/recommends/superadmin-app/', 'description' => 'Turn your POS to SaaS application and start earning by selling subscriptions'],
            'Woocommerce' => ['name' => 'Woocommerce Module', 'url' => 'https://ultimatefosters.com/recommends/woocommerce-app/', 'description' => 'Sync your Woocommerce store with POS'],
            'Manufacturing' => ['name' => 'Manufacturing Module', 'url' => 'https://ultimatefosters.com/recommends/manufacturing-app/', 'description' => 'Manufacture products from raw materials, organise recipe & ingredients'],
            'Project' => ['name' => 'Project Module', 'url' => 'https://ultimatefosters.com/recommends/project-app/', 'description' => 'Manage Projects, tasks, tasks time logs, activities and much more.'],
            'Repair' => ['name' => 'Repair Module', 'url' => 'https://ultimatefosters.com/recommends/repair-app/', 'description' => 'Repair module helps with complete repair service management of electronic goods like Cellphone, Computers, Desktops, Tablets, Television, Watch, Wireless devices, Printers, Electronic instruments and many more similar devices which you can imagine!'],
            'Crm' => ['name' => 'CRM Module', 'url' => 'https://ultimatefosters.com/product/crm-module-for-ultimatepos/', 'description' => 'Customer relationship management module'],
            'ProductCatalogue' => ['name' => 'ProductCatalogue', 'url' => 'https://codecanyon.net/item/digital-product-catalogue-menu-module-for-ultimatepos/28825346', 'description' => 'Digital Product catalogue Module'],
            'Accounting' => ['name' => 'Accounting Module', 'url' => 'https://ultimatefosters.com/product/accounting-bookkeeping-module-for-ultimatepos/', 'description' => 'Accounting & Book keeping module for UltimatePOS'],
            'AiAssistance' => ['name' => 'AiAssistance Module', 'url' => 'https://ultimatefosters.com/product/ai-assistance-module-for-ultimatepos/', 'description' => 'AI Assistant module for UltimatePOS. This module used openAI API to help with in copywriting & reporting'],
            'AssetManagement' => ['name' => 'AssetManagement Module', 'url' => 'https://ultimatefosters.com/product/asset-management-module-for-ultimatepos/', 'description' => 'Useful for managing all kinds of assets.'],
            'Cms' => ['name' => 'Cms Module', 'url' => 'https://ultimatefosters.com/product/ultimatepos-cms-module/', 'description' => 'Mini CMS (content management system) Module for UltimatePOS to help manage all frontend contents like Landing page, Blogs, Contact us & many other pages.'],
            'Connector' => ['name' => 'Connector/API Module', 'url' => 'https://ultimatefosters.com/product/rest-api-module-for-ultimatepos/', 'description' => 'Provide the API for POS.'],
            'Gym' => ['name' => 'Gym Module', 'url' => 'https://ultimatefosters.com/product/gym-management-module-for-ultimatepos/', 'description' => 'Gym Management module for UltimatePOS'],
            'Hms' => ['name' => 'Hotel Management Module', 'url' => 'https://ultimatefosters.com/product/hms-hotel-management-system-module-for-ultimatepos/', 'description' => 'Hotel Management System module for UltimatePOS, provides features for room bookings, extras, coupons & related features'],
            'CustomDashboard' => ['name' => 'Custom Dashboard Module', 'url' => 'https://ultimatefosters.com/product/custom-dashboard-module-for-ultimatepos/', 'description' => 'Create customised dashboard as per your need'],
            'ZatcaIntegrationKsa' => ['name' => 'ZATCA Integration Module', 'url' => 'https://ultimatefosters.com/product/zatca-integration-module-for-ultimatepos/', 'description' => 'This module is used to integrate UltimatePOS with ZATCA, Saudi Arabia'],
        ];
    }

    /**
     * Largest module zip that can be uploaded, as a readable size.
     * The smallest of PHP's upload limits and the 10MB validation limit.
     */
    private function __maxUploadSize()
    {
        $to_bytes = function ($value) {
            $value = trim((string) $value);
            $number = (float) $value;
            switch (strtolower(substr($value, -1))) {
                case 'g': $number *= 1024;
                case 'm': $number *= 1024;
                case 'k': $number *= 1024;
            }

            return $number;
        };

        $limits = [10240 * 1024, $to_bytes(ini_get('upload_max_filesize')), $to_bytes(ini_get('post_max_size'))];
        $bytes = min(array_filter($limits));

        return round($bytes / 1024 / 1024, 1).'MB';
    }
}
