@extends('layouts.install')
@section('title', 'Installation/Update')

@php
    $input_class = 'tw-w-full tw-border tw-border-[#D1D5DA] tw-outline-none tw-h-12 tw-bg-transparent tw-rounded-lg tw-px-3 tw-font-medium tw-text-black placeholder:tw-text-gray-500 placeholder:tw-font-medium focus:tw-border-indigo-500 focus:tw-ring-2 focus:tw-ring-indigo-100';
    $link_class = 'tw-font-medium tw-bg-gradient-to-r tw-from-indigo-500 tw-to-blue-500 tw-inline-block tw-text-transparent tw-bg-clip-text hover:tw-text-[#467BF5]';
@endphp

@section('content')
<div class="tw-min-h-screen tw-bg-gray-100 tw-py-12 tw-px-4">
    <div class="tw-w-full md:tw-w-3/4 lg:tw-w-1/2 tw-mx-auto tw-bg-white tw-rounded-2xl tw-shadow-sm tw-ring-1 tw-ring-gray-200 tw-p-5 md:tw-p-6">

        <h2 class="tw-text-lg md:tw-text-xl tw-font-semibold tw-text-[#1e1e1e] tw-mt-0 tw-mb-1">
            {{ $action_type == 'update' ? 'Update' : 'Install' }}
            <span class="tw-bg-gradient-to-r tw-from-indigo-500 tw-to-blue-500 tw-inline-block tw-text-transparent tw-bg-clip-text">{{ $module_display_name }} Module</span>
        </h2>
        <p class="tw-text-sm tw-font-medium tw-text-red-600 tw-mb-0">Enter your license details to continue. Make sure they are correct.</p>

        <hr class="tw-border-0 tw-border-t tw-border-gray-200 tw-my-6"/>

        @if(session('error'))
            <div class="alert alert-danger tw-rounded-lg tw-border-0">
                {!! session('error') !!}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger tw-rounded-lg tw-border-0">
                <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
                </ul>
            </div>
        @endif

        <form class="form" id="details_form" method="post" action="{{$action_url}}">
            {{ csrf_field() }}

            {{-- 'both': module is sold on CodeCanyon and ultimatefosters.com --}}
            @if($intruction_type == 'both')
                <p class="tw-text-xs tw-text-gray-500 tw-mb-2.5">Use the details from where you bought the module:</p>
                <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4 tw-mb-6">
                    <div class="tw-rounded-xl tw-border tw-border-gray-200 tw-bg-gray-50 tw-p-4">
                        <p class="tw-text-sm tw-font-semibold tw-text-[#1e1e1e] tw-mb-2">Bought on CodeCanyon (Envato)</p>
                        <div class="tw-divide-y tw-divide-dashed tw-divide-gray-200 tw-text-sm">
                            <div class="tw-flex tw-justify-between tw-gap-3 tw-py-1.5">
                                <span class="tw-text-gray-500">License Code</span>
                                <a href="https://help.market.envato.com/hc/en-us/articles/202822600-Where-Is-My-Purchase-Code-#:~:text=Hover%20the%20mouse%20over%20your,as%20PDF%20or%20text%20file)." target="_blank" class="{{ $link_class }}">Find your purchase code</a>
                            </div>
                            <div class="tw-flex tw-justify-between tw-gap-3 tw-py-1.5">
                                <span class="tw-text-gray-500">Username</span>
                                <span class="tw-font-medium tw-text-[#1e1e1e]">Your Envato username</span>
                            </div>
                        </div>
                    </div>
                    <div class="tw-rounded-xl tw-border tw-border-gray-200 tw-bg-gray-50 tw-p-4">
                        <p class="tw-text-sm tw-font-semibold tw-text-[#1e1e1e] tw-mb-2">Bought on ultimatefosters.com</p>
                        <div class="tw-divide-y tw-divide-dashed tw-divide-gray-200 tw-text-sm">
                            <div class="tw-flex tw-justify-between tw-gap-3 tw-py-1.5">
                                <span class="tw-text-gray-500">License Code</span>
                                <a href="https://ultimatefosters.com/docs/ultimate-fosters-shop/license-key/#Getting-License-Details-for-products-purchased-from-ultimatefosterscom" target="_blank" class="{{ $link_class }}">Find your license key</a>
                            </div>
                            <div class="tw-flex tw-justify-between tw-gap-3 tw-py-1.5">
                                <span class="tw-text-gray-500">Username</span>
                                <a href="https://ultimatefosters.com/docs/ultimate-fosters-shop/user-name/#Steps-to-get-your-username-for-all-products-purchased-from-UltimateFosters" target="_blank" class="{{ $link_class }}">Find your username</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
                <div class="tw-dw-form-control">
                    <label class="tw-dw-label tw-pt-0" for="license_code">
                        <span class="tw-text-xs md:tw-text-sm tw-font-medium tw-text-black">License Code *</span>
                    </label>
                    <input type="text" name="license_code" required class="{{ $input_class }}" id="license_code">

                    @if($intruction_type == 'uf')
                        <p class="tw-text-xs tw-mt-1.5 tw-mb-0"><a href="https://ultimatefosters.com/docs/ultimate-fosters-shop/license-key/#Getting-License-Details-for-products-purchased-from-ultimatefosterscom" target="_blank" class="{{ $link_class }}">Where is my License Key?</a></p>
                    @endif

                    @if($intruction_type == 'cc')
                        <p class="tw-text-xs tw-mt-1.5 tw-mb-0"><a href="https://help.market.envato.com/hc/en-us/articles/202822600-Where-Is-My-Purchase-Code-#:~:text=Hover%20the%20mouse%20over%20your,as%20PDF%20or%20text%20file)." target="_blank" class="{{ $link_class }}">Where is my License Key?</a></p>
                    @endif
                </div>

                <div class="tw-dw-form-control">
                    <label class="tw-dw-label tw-pt-0" for="login_username">
                        <span class="tw-text-xs md:tw-text-sm tw-font-medium tw-text-black">Login Username *</span>
                    </label>
                    <input type="text" name="login_username" required class="{{ $input_class }}" id="login_username">

                    @if($intruction_type == 'uf')
                        <p class="tw-text-xs tw-mt-1.5 tw-mb-0"><a href="https://ultimatefosters.com/docs/ultimate-fosters-shop/user-name/#Steps-to-get-your-username-for-all-products-purchased-from-UltimateFosters" target="_blank" class="{{ $link_class }}">Where is my Username?</a></p>
                    @endif

                    @if($intruction_type == 'cc')
                        <p class="tw-text-xs tw-text-gray-500 tw-mt-1.5 tw-mb-0">Enter the username that you use for codecanyon or envato login</p>
                    @endif
                </div>

                <div class="tw-dw-form-control">
                    <label class="tw-dw-label tw-pt-0" for="envato_email">
                        <span class="tw-text-xs md:tw-text-sm tw-font-medium tw-text-black">Your Email</span>
                    </label>
                    <input type="email" name="ENVATO_EMAIL" class="{{ $input_class }}" id="envato_email" placeholder="Optional">
                    <p class="tw-text-xs tw-text-gray-500 tw-mt-1.5 tw-mb-0">For newsletter &amp; support</p>
                </div>
            </div>

            @if($intruction_type == 'cc')
                <div class="row">
                    @include('install.partials.e_license')
                </div>
            @endif

            <div class="tw-flex tw-justify-end tw-mt-7">
                <button type="submit" id="install_button" class="tw-w-full sm:tw-w-auto tw-px-7 tw-bg-gradient-to-r tw-from-indigo-500 tw-to-blue-500 tw-h-12 tw-rounded-xl tw-text-sm md:tw-text-base tw-text-white tw-font-semibold hover:tw-from-indigo-600 hover:tw-to-blue-600 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-blue-500 focus:tw-ring-offset-2 active:tw-from-indigo-700 active:tw-to-blue-700 disabled:tw-opacity-70">
                    {{ $intruction_type == 'cc' ? 'I Agree, Submit' : ($action_type == 'update' ? 'Update Module' : 'Install Module') }}
                </button>
            </div>
        </form>

    </div>
</div>
@endsection

@section('javascript')
  <script type="text/javascript">
    $(document).ready(function(){
      $('form#details_form').submit(function(){
        $('button#install_button').attr('disabled', true).text('Installing...');
        $('div.install_msg').removeClass('hide');
        $('.back_button').hide();
      });
    })
  </script>
@endsection
