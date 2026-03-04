<!--Changes the active tab on the sidebar-->
@php
    $home = "<li>";
    $view = "<li>";
    $list = "<li>";
    $search = "<li>";
    $receive = "<li>";
    $new = "<li>";
    $settings = "<li>";
    $report = "<li>";
    $record = "<li>";
@endphp
@switch(Request::segment(1))
    @case('view_transaction')
        <?php $view = "<li class='active'>"; ?>
        @break
    @case('list_transaction')
        <?php $list = "<li class='active'>"; ?>
        @break
    @case('search_transactions')
    @case('get_transactions')
        <?php $search = "<li class='active'>"; ?>
        @break
    @case('receive_transaction')
        <?php $receive = "<li class='active'>"; ?>
        @break
    @case('new_transaction')
        <?php $new = "<li class='active'>"; ?>
        @break
    @case('records')
    @case('categories')
    @case('reports')
        <?php $record = "<li class='active'>"; ?>
        @break
    @case('flows')
    @case('offices')
    @case('users')
    @case('customize')
        <?php $settings = "<li class='active'>"; ?>
        @break
    @default
        <?php $home = "<li class='active'>"; ?> 
@endswitch

<section>
	<!-- Left Sidebar -->
	<aside id="leftsidebar" class="sidebar">
		<!-- User Info -->
		<div class="user-info">
			<div class="image">
                <img src="{{asset( $school->code ?? 'RMS' )}}" width="48" height="48" alt="User" />
            </div>
            <div class="info-container">
                <div class="name" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    @if(!Auth::user()->fname)
                        {!!Auth::user()->name!!}
                    @else
                        {!!Auth::user()->lname!!}, {!!Auth::user()->fname!!} {!!Auth::user()->mi!!}     
                    @endif
                </div>
                <div class="btn-group user-helper-dropdown">
                    <i class="material-icons" data-toggle="dropdown" aria-haspopup="true" aria-expanded="true">keyboard_arrow_down</i>
                    <ul class="dropdown-menu pull-right">
                        <li><a href="/users/{!!Auth::id()!!}"><i class="material-icons">person</i>Profile</a></li>
                        <li role="separator" class="divider"></li>
                        <li>
                            <a href="/passwords/{!!Auth::id()!!}/edit">
                                <i class="material-icons">autorenew</i>
                                Update Password
                            </a>
                        </li>
                        <li>
                            <a href="/logout">
                                <i class="material-icons">input</i>
                                Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
		</div>
		<!-- #User Info -->
		<!-- Menu -->
		<div class="menu">
			<ul class="list">
                {!!$home!!}
                    <a href="/">
                        <i class="material-icons">assignment</i>
                        <span>Current Transactions</span>
                    </a>               
                </li>
                {!!$list!!}
                    <a href="javascript:void(0);" class="menu-toggle">
                            <i class="material-icons">list</i>
                            <span>List Transactions</span>
                        </a>
                        <ul class="ml-menu">
                            <li><a href="/list_transaction/1">My Transactions</a></li>
                            <li><a href="/list_transaction/2">Incoming Transactions</a></li>
                            <li><a href="/list_transaction/3">Outgoing Transactions</a></li>
                            <li><a href="/list_transaction/4">CF Received</a></li>
                        </ul> 
                    </li>
                {!!$search!!}
                    <a href="/search_transactions">
                        <i class="material-icons">search</i>
                        <span>Search Transactions</span>
                    </a>               
                </li>
                {!!$receive!!}
                    <a href="/receive_transaction">
                        <i class="material-icons">file_download</i>
                        <span>Receive Transaction</span>
                    </a>
                </li>
                {!!$new!!}
                    {{-- <a href="/new_transaction">
                        <i class="material-icons">note_add</i>
                        <span>Create Transaction</span>
                    </a>               
                </li> --}}
                        <a href="javascript:void(0);" class="menu-toggle">
                            <i class="material-icons">note_add</i>
                            <span>Create Transaction</span>
                        </a>
                        <ul class="ml-menu">
                            <li><a href="/new_transaction/1">Internal Transaction</a></li>
                            <li><a href="/new_transaction/2">Memorandum | Free Flow</a></li>
                        </ul> 
                    </li>
                {!!$record!!}
                    <a href="javascript:void(0);" class="menu-toggle">
                        <i class="material-icons">library_books</i>
                        <span>Records Archival</span>
                    </a>
                    <ul class="ml-menu">
                        <li><a href="/records/create">Add Records</a></li>
                        <li><a href="/records/categories">Record Categories</a></li>
                        @if(Auth::user()->office == 1)
                            <li><a href="/records/search">Search Records</a></li> 
                            <li><a href="/reports/disposition">Disposition</a></li>
                            <li><a href="/records/offices">Offices</a></li>
                        @endif
                        <li><a href="/reports/memos">Memos</a></li>
                    </ul>
                </li>
                {!!$settings!!}
                    <a href="javascript:void(0);" class="menu-toggle">
                        <i class="material-icons">settings</i>
                        <span>Settings</span>
                    </a>
                    <ul class="ml-menu">
                        <li><a href="/flows">Transaction Flows</a></li>
                        @if (Auth::user()->office == 1)
                            <li><a href="/offices">Offices</a></li>
                            <li><a href="/users">Users</a></li>
                        @endif
                        @if (Auth::id() == 103)
                            <li><a href="/customize">Customize</a></li>
                        @endif
                    </ul>
                </li>      
			</ul>
		</div>
		<!-- #Menu -->
		<!-- Footer -->
		<div class="legal">
			<div class="copyright">
                &copy; 2019
            </div>
            <div class="version">
                <b>Version: </b> 1.0.0
            </div>
		</div>
		<!-- #Footer -->
	</aside>
	<!-- #END# Left Sidebar -->
</section>