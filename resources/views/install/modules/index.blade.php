@extends('layouts.app')
@section('title', __('lang_v1.manage_modules'))

@php
    $card_class = 'tw-flex tw-flex-col tw-p-4 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 tw-ring-gray-200 hover:tw-shadow-md tw-transition-all tw-duration-200';
    $badge_class = 'tw-dw-badge tw-dw-badge-sm tw-border tw-font-semibold tw-whitespace-nowrap';
@endphp

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">
        @lang('lang_v1.manage_modules')
        <small class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">Only superadmin can access manage modules</small>
    </h1>

    <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm tw-mt-4" data-toggle="modal" data-target="#upload_module_modal">
        <i class="fas fa-upload"></i>
        @lang('lang_v1.upload_module')
    </button>

    <a class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm pull-right tw-mt-4" href="{{action([\App\Http\Controllers\Install\ModulesController::class, 'regenerate'])}}">
        <i class="fas fa-tools"></i>
        Regenerate @show_tooltip("<br/>1. Regenerate/publish modules css/js to fix not found issue. <br/> 2. Publish api module oauth files")
    </a>
</section>

<!-- Main content -->
<section class="content">

    @php
        $pending_install_count = collect($modules)->where('is_installed', false)->count();
        $update_count = collect($modules)->filter(function ($module) {
            return $module['is_installed'] && ! empty($module['version']['is_update_available']);
        })->count();
        $notice_class = 'module_filter tw-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2.5 tw-rounded-xl tw-border tw-text-sm tw-font-semibold tw-transition-all';
    @endphp

    @if($pending_install_count || $update_count)
        <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-mb-5">
            @if($update_count)
                <button type="button" class="{{ $notice_class }} tw-bg-yellow-50 tw-border-yellow-200 tw-text-yellow-700 hover:tw-bg-yellow-100" data-filter="update">
                    <i class="fas fa-sync"></i>
                    {{ trans_choice('lang_v1.modules_update_available', $update_count, ['count' => $update_count]) }}
                    <i class="fas fa-filter tw-text-xs tw-opacity-60"></i>
                </button>
            @endif
            @if($pending_install_count)
                <button type="button" class="{{ $notice_class }} tw-bg-indigo-50 tw-border-indigo-200 tw-text-indigo-700 hover:tw-bg-indigo-100" data-filter="not_installed">
                    <i class="fas fa-download"></i>
                    {{ trans_choice('lang_v1.modules_pending_installation', $pending_install_count, ['count' => $pending_install_count]) }}
                    <i class="fas fa-filter tw-text-xs tw-opacity-60"></i>
                </button>
            @endif
            <a href="#" id="show_all_modules" class="hide tw-text-sm tw-font-medium tw-text-gray-600 hover:tw-text-gray-900 tw-underline">
                @lang('lang_v1.show_all_modules')
            </a>
        </div>
    @endif

    <h2 class="tw-text-lg tw-font-semibold tw-text-gray-900 tw-mt-0 tw-mb-3">@lang('lang_v1.your_modules')</h2>

    @php
        //Modules needing action first: updates, then not installed, then installed.
        $sorted_modules = collect($modules)->sortBy(function ($module) {
            $has_update = $module['is_installed'] && ! empty($module['version']['is_update_available']);
            $rank = $has_update ? 0 : ($module['is_installed'] ? 2 : 1);

            return $rank.strtolower($module['name']);
        });
    @endphp

    <div class="tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 tw-ring-gray-200 tw-divide-y tw-divide-gray-100">
        <div class="tw-hidden md:tw-flex tw-items-center tw-gap-4 tw-px-4 tw-py-2.5 tw-bg-gray-50 tw-rounded-t-xl tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-gray-500">
            <div class="tw-w-8">#</div>
            <div class="tw-flex-1">@lang('lang_v1.modules')</div>
            <div class="tw-w-36">@lang('sale.status')</div>
            <div class="tw-w-28">@lang('lang_v1.version')</div>
            <div class="tw-w-52"></div>
        </div>

        @foreach($sorted_modules as $module)
            @php
                $is_installed = $module['is_installed'];
                $has_update = $is_installed && ! empty($module['version']['is_update_available']);
            @endphp

            <div class="module_card tw-flex tw-flex-col md:tw-flex-row md:tw-items-center tw-gap-2 md:tw-gap-4 tw-px-4 tw-py-3 hover:tw-bg-gray-50" data-status="{{ $has_update ? 'update' : ($is_installed ? 'installed' : 'not_installed') }}">
                <div class="tw-hidden md:tw-block tw-w-8 tw-text-sm tw-text-gray-500">{{ $loop->iteration }}</div>
                <div class="tw-flex-1 tw-min-w-0">
                    <h3 class="tw-text-sm tw-font-semibold tw-text-gray-900 tw-m-0 tw-break-all">{{ $module['name'] }}</h3>
                    <p class="tw-text-sm tw-text-gray-500 tw-mt-0.5 tw-mb-0">{{ $module['description'] }}</p>
                </div>

                <div class="md:tw-w-36">
                    @if($has_update)
                        <span class="{{ $badge_class }} tw-bg-yellow-50 tw-border-yellow-200 tw-text-yellow-700">@lang('lang_v1.update_available')</span>
                    @elseif($is_installed)
                        <span class="{{ $badge_class }} tw-bg-green-50 tw-border-green-200 tw-text-green-700">@lang('lang_v1.installed')</span>
                    @else
                        <span class="{{ $badge_class }} tw-bg-gray-100 tw-border-gray-200 tw-text-gray-600">@lang('lang_v1.not_installed')</span>
                    @endif
                </div>

                <div class="md:tw-w-28 tw-text-sm tw-text-gray-700">
                    <span class="md:tw-hidden tw-text-gray-500">@lang('lang_v1.version'):</span>
                    @if($has_update)
                        {{ $module['version']['installed_version'] }} &rarr; <span class="tw-font-semibold">{{ $module['version']['available_version'] }}</span>
                    @elseif($is_installed)
                        {{ $module['version']['installed_version'] }}
                    @else
                        <span class="tw-text-gray-400">&ndash;</span>
                    @endif
                </div>

                <div class="md:tw-w-52 tw-flex tw-items-center md:tw-justify-end tw-gap-1">
                    @if(! $is_installed)
                        <a class="tw-dw-btn tw-dw-btn-primary tw-dw-btn-sm tw-text-white"
                            @if($is_demo) href="#" disabled title="@lang('lang_v1.disabled_in_demo')" @else href="{{ $module['install_link'] }}" @endif
                        >@lang('lang_v1.install')</a>
                    @elseif($has_update)
                        <a class="tw-dw-btn tw-dw-btn-primary tw-dw-btn-sm tw-text-white" href="{{ $module['update_link'] }}">
                            <i class="fas fa-sync"></i> @lang('lang_v1.update_to_version', ['version' => $module['version']['available_version']])
                        </a>
                    @endif

                    <div class="btn-group">
                        <button type="button" class="tw-dw-btn tw-dw-btn-ghost tw-dw-btn-sm tw-dw-btn-square dropdown-toggle" data-toggle="dropdown" aria-expanded="false" title="@lang('lang_v1.more_options')">
                            <i class="fas fa-ellipsis-v tw-text-gray-500"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-right" role="menu">
                            @if($is_installed)
                                <li @if($is_demo) class="disabled" title="@lang('lang_v1.disabled_in_demo')" @endif>
                                    <a href="#" @if(! $is_demo) class="uninstall_module" data-href="{{ $module['uninstall_link'] }}" @endif>
                                        <i class="fas fa-power-off"></i> @lang('lang_v1.uninstall')
                                    </a>
                                </li>
                            @endif
                            <li @if($is_demo) class="disabled" title="@lang('lang_v1.disabled_in_demo')" @endif>
                                <a href="#" class="!tw-text-red-600 @if(! $is_demo) delete_module @endif" data-form="#delete_module_{{ $module['name'] }}">
                                    <i class="fas fa-trash"></i> @lang('messages.delete')
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <form id="delete_module_{{ $module['name'] }}" method="post" class="hide"
                    action="{{action([\App\Http\Controllers\Install\ModulesController::class, 'destroy'], ['module_name' => $module['name']])}}">
                    @method('DELETE')
                    @csrf
                </form>
            </div>
        @endforeach
    </div>

    @if(! empty($buy_modules))
        <div id="buy_modules_section">
        <h2 class="tw-text-lg tw-font-semibold tw-text-gray-900 tw-mt-8 tw-mb-3">@lang('lang_v1.get_more_modules')</h2>

        <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 xl:tw-grid-cols-3 tw-gap-4">
            @foreach($buy_modules as $buy_module)
                <div class="{{ $card_class }}">
                    <h3 class="tw-text-base tw-font-semibold tw-text-gray-900 tw-m-0">{{ $buy_module['name'] }}</h3>
                    <p class="tw-text-sm tw-text-gray-500 tw-mt-1.5 tw-mb-0">{{ $buy_module['description'] }}</p>
                    <div class="tw-mt-auto tw-pt-4">
                        <a class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary tw-dw-btn-sm" href="{{ $buy_module['url'] }}" target="_blank" rel="noopener">
                            <i class="fas fa-shopping-cart"></i> @lang('lang_v1.buy_module')
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
        </div>
    @endif

</section>

<!-- Upload module modal -->
<div class="modal fade" id="upload_module_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            {!! Form::open(['url' => action([\App\Http\Controllers\Install\ModulesController::class, 'uploadModule']), 'id' => 'upload_module_form', 'files' => true]) !!}
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">@lang('lang_v1.upload_module')</h4>
                </div>
                <div class="modal-body">
                    <label for="module_zip" id="module_drop_zone" class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-gap-2 tw-w-full tw-p-8 tw-m-0 tw-border-2 tw-border-dashed tw-border-gray-300 tw-rounded-xl tw-bg-gray-50 tw-cursor-pointer tw-text-center tw-transition-colors hover:tw-border-indigo-400 hover:tw-bg-indigo-50">
                        <i class="fas fa-file-archive fa-2x tw-text-gray-400"></i>
                        <span id="module_zip_name" class="tw-text-sm tw-font-medium tw-text-gray-700">@lang('lang_v1.drop_zip_or_browse')</span>
                        <span class="tw-text-xs tw-font-normal tw-text-gray-500">@lang('lang_v1.max_upload_size', ['size' => $max_upload_size])</span>
                    </label>
                    <input type="file" name="module" id="module_zip" accept=".zip,application/zip" required class="tw-sr-only">
                    <p class="tw-text-xs tw-text-gray-500 tw-mt-3 tw-mb-0">@lang('lang_v1.upload_module_zip_help')</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="tw-dw-btn tw-dw-btn-ghost tw-dw-btn-sm" data-dismiss="modal">@lang('messages.cancel')</button>
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-dw-btn-sm tw-text-white"
                        @if($is_demo) disabled title="@lang('lang_v1.disabled_in_demo')" @endif
                    >@lang('lang_v1.upload')</button>
                </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>
@endsection

@section('javascript')
<script type="text/javascript">
    //Show the chosen file name in the drop zone.
    function showModuleZipName() {
        var file = $('#module_zip')[0].files[0];
        $('#module_zip_name').text(file ? file.name : "@lang('lang_v1.drop_zip_or_browse')");
    }

    $(document).on('change', '#module_zip', showModuleZipName);

    $(document).on('dragover dragenter', '#module_drop_zone', function(e) {
        e.preventDefault();
        $(this).addClass('tw-border-indigo-400 tw-bg-indigo-50');
    });

    $(document).on('dragleave drop', '#module_drop_zone', function(e) {
        e.preventDefault();
        $(this).removeClass('tw-border-indigo-400 tw-bg-indigo-50');
    });

    $(document).on('drop', '#module_drop_zone', function(e) {
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) {
            $('#module_zip')[0].files = files;
            showModuleZipName();
        }
    });

    //Reset the form when the modal is closed.
    $('#upload_module_modal').on('hidden.bs.modal', function() {
        $('#upload_module_form')[0].reset();
        showModuleZipName();
    });

    //Filter cards by status from the notices; click the active notice again to show all.
    function filterModules(status) {
        $('.module_filter').removeClass('tw-ring-2 tw-ring-offset-1 tw-ring-current');
        if (status) {
            $('.module_filter[data-filter="' + status + '"]').addClass('tw-ring-2 tw-ring-offset-1 tw-ring-current');
            $('.module_card').each(function() {
                $(this).toggle($(this).data('status') == status);
            });
        } else {
            $('.module_card').show();
        }
        $('#show_all_modules').toggleClass('hide', ! status);
        $('#buy_modules_section').toggle(! status);
    }

    $(document).on('click', '.module_filter', function() {
        var status = $(this).data('filter');
        filterModules($(this).hasClass('tw-ring-2') ? null : status);
    });

    $(document).on('click', '#show_all_modules', function(e) {
        e.preventDefault();
        filterModules(null);
    });

    $(document).on('click', '.uninstall_module', function(e) {
        e.preventDefault();
        var href = $(this).data('href');
        swal({
            title: LANG.sure,
            text: "@lang('lang_v1.uninstall_module_confirm')",
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(function(confirmed) {
            if (confirmed) {
                window.location.href = href;
            }
        });
    });

    $(document).on('click', '.delete_module', function(e) {
        e.preventDefault();
        var form = $(this).data('form');
        swal({
            title: LANG.sure,
            text: "@lang('lang_v1.delete_module_confirm')",
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(function(confirmed) {
            if (confirmed) {
                $(form).submit();
            }
        });
    });
</script>
@endsection
