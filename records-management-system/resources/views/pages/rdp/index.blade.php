<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Dashboard')] class extends Component {
    //
};
?>

<div>
    <style>
        .rdp-fab-nav {
            position: fixed;
            right: 32px;
            bottom: 32px;
            display: flex;
            gap: 17px;
            z-index: 1100;
            align-items: center;
        }
        .rdp-fab-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 14px 20px;
            background: linear-gradient(180deg,#3B6AC1 0%,#2f57c6 100%);
            color: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 18px rgba(39,57,88,0.18);
            border: none;
            cursor: pointer;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-size: 13px;
            min-width: 160px;
            height: 38px;
            justify-content: center;
        }
        .rdp-fab-btn svg { fill: #fff; width: 20px; height: 20px; flex: 0 0 28px; }
        .rdp-fab-btn span { display: inline-block; }
        .rdp-fab-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(39,57,88,0.22); }
        @media (max-width: 800px) {
            .rdp-fab-nav { right: 12px; bottom: 12px; gap: 12px; flex-direction: column-reverse; align-items: flex-end; }
            .rdp-fab-btn { min-width: 180px; height: 56px; padding: 12px 20px; font-size: 15px; }
        }
    </style>

    <div class="rdp-fab-nav" aria-hidden="false">
        <button class="rdp-fab-btn" type="button" aria-label="View">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5C7 5 2.73 8.11 1 12c1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/><circle cx="12" cy="12" r="2.5"/></svg>
            <span>View</span>
        </button>

        <button class="rdp-fab-btn" type="button" aria-label="Upload File">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
<path d="M6.70833 11.5V3.68958L4.21667 6.18125L2.875 4.79167L7.66667 0L12.4583 4.79167L11.1167 6.18125L8.625 3.68958V11.5H6.70833ZM1.91667 15.3333C1.38958 15.3333 0.938528 15.1458 0.5635 14.7708C0.188472 14.3958 0.000638889 13.9444 0 13.4167V10.5417H1.91667V13.4167H13.4167V10.5417H15.3333V13.4167C15.3333 13.9437 15.1458 14.3951 14.7708 14.7708C14.3958 15.1465 13.9444 15.334 13.4167 15.3333H1.91667Z" fill="#EEEEEE"/>
</svg>
            <span>Upload File</span>
        </button>

        <button class="rdp-fab-btn" type="button" aria-label="Save Draft">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="17" viewBox="0 0 20 17" fill="none">
<path d="M18.2568 14.8809C18.5329 14.8809 18.7568 15.1048 18.7568 15.3809C18.7567 15.6568 18.5328 15.8808 18.2568 15.8809H1C0.723946 15.8809 0.500143 15.6569 0.5 15.3809C0.5 15.1047 0.723858 14.8809 1 14.8809H18.2568ZM16.9443 0.646484C17.1396 0.451248 17.4561 0.451299 17.6514 0.646484C17.8466 0.841747 17.8466 1.15825 17.6514 1.35352L7.10547 11.8994C7.01172 11.9931 6.88446 12.0459 6.75195 12.0459C6.61945 12.0458 6.49214 11.9931 6.39844 11.8994L1.60547 7.10547C1.41021 6.91021 1.41021 6.5937 1.60547 6.39844C1.80075 6.20346 2.11733 6.20327 2.3125 6.39844L6.75195 10.8379L16.9443 0.646484Z" stroke="#EEEEEE" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
            <span>Save Draft</span>
        </button>

        <button class="rdp-fab-btn" type="button" aria-label="Create Record">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
<path d="M21 14V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H10V5H5V19H19V14H21Z" fill="#EEEEEE"/>
<path d="M21 7H17V3H15V7H11V9H15V13H17V9H21V7Z" fill="#EEEEEE"/>
</svg>
            <span>Create Record</span>
        </button>
    </div>
</div>
