<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Applications')] class extends Component {
    public $activeTab = 'applications';
    public $searchQuery = '';
};
?>



<style>
    :root {
        --primary-blue: #003699;
        --text-dark: #1F2937;
        --text-gray: #525252;
        --border-gray: #E5E7EB;
        --bg-light-gray: #F9FAFB;
        --bg-hover: #F0F4F8;
        --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    
    * {
        font-family: 'Inter', 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
</style>




<div class="min-h-screen" style="background-color: #F5F5F5; padding: 32px 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; padding: 24px 32px; margin-bottom: 20px; flex-wrap: wrap; gap: 20px;">
        <div style="display: flex; gap: 8px; align-items: center;">
            <a 
                href="{{ route('dts') }}"
                wire:navigate
                style="
                    padding: 8px 16px;
                    border-radius: 20px;
                    border: none;
                    font-weight: 500;
                    font-size: 14px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    background-color: transparent;
                    color: var(--text-gray);
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    margin-left: -30px;
                    text-decoration: none;
                "
                @mouseover="this.style.opacity = '0.8'"
                @mouseout="this.style.opacity = '1'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 19 19" fill="none" style="flex-shrink: 0;">
                  <path d="M6.98828 10.8447C7.31464 10.8447 7.57578 10.9523 7.81152 11.1885C8.04812 11.4256 8.15571 11.6876 8.15625 12.0137V17.083C8.15625 17.4094 8.04873 17.6705 7.8125 17.9062C7.57563 18.1426 7.31408 18.25 6.98828 18.25H1.91895C1.59147 18.25 1.33023 18.1422 1.09473 17.9062C0.887473 17.6985 0.77902 17.4723 0.754883 17.2012L0.75 17.082V12.0127C0.750012 11.6863 0.857514 11.4252 1.09375 11.1895C1.30126 10.9824 1.52792 10.8747 1.7998 10.8506L1.91895 10.8447H6.98828ZM17.082 10.8438C17.4084 10.8438 17.6695 10.9513 17.9053 11.1875C18.1124 11.3951 18.221 11.6216 18.2451 11.8936L18.25 12.0127V17.082C18.25 17.4084 18.1425 17.6695 17.9062 17.9053C17.6691 18.1419 17.4072 18.2495 17.0811 18.25H12.0127C11.6852 18.25 11.424 18.1422 11.1885 17.9062C10.9814 17.6987 10.8728 17.4722 10.8486 17.2002L10.8438 17.0811V12.0117C10.8438 11.6853 10.9513 11.4242 11.1875 11.1885C11.3951 10.9814 11.6216 10.8728 11.8936 10.8486L12.0127 10.8438H17.082ZM6.98828 0.75C7.31465 0.75 7.57577 0.857501 7.81152 1.09375C8.01861 1.3013 8.12722 1.52784 8.15137 1.7998L8.15625 1.91895V6.98828C8.15625 7.31465 8.04875 7.57577 7.8125 7.81152C7.57537 8.04812 7.31347 8.15576 6.9873 8.15625H1.91895C1.59147 8.15624 1.33023 8.0485 1.09473 7.8125C0.887637 7.60495 0.779032 7.37841 0.754883 7.10645L0.75 6.9873V1.91797C0.75 1.5916 0.857501 1.33048 1.09375 1.09473C1.3013 0.887637 1.52784 0.779032 1.7998 0.754883L1.91895 0.75H6.98828ZM17.082 0.75C17.4084 0.75 17.6695 0.857501 17.9053 1.09375C18.1124 1.3013 18.221 1.52784 18.2451 1.7998L18.25 1.91895V6.98828C18.25 7.31465 18.1425 7.57577 17.9062 7.81152C17.6691 8.04812 17.4072 8.15576 17.0811 8.15625H12.0127C11.6852 8.15624 11.424 8.0485 11.1885 7.8125C10.9814 7.60495 10.8728 7.37841 10.8486 7.10645L10.8438 6.9873V1.91797C10.8438 1.5916 10.9513 1.33048 11.1875 1.09473C11.3951 0.887637 11.6216 0.779032 11.8936 0.754883L12.0127 0.75H17.082Z" stroke="currentColor" stroke-width="1.5"/>
                </svg> All Records
            </a>
            
            <a 
                href="{{ route('dts.internal') }}"
                wire:navigate
                style="
                    padding: 8px 16px;
                    border-radius: 10px;
                    border: none;
                    font-weight: 500;
                    font-size: 14px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    background-color: transparent;
                    color: var(--text-gray);
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    text-decoration: none;
                "
                @mouseover="this.style.opacity = '0.8'"
                @mouseout="this.style.opacity = '1'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 26 26" fill="none" style="flex-shrink: 0;">
                  <path d="M22 22H3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M17.25 22V6.8C17.25 5.0083 17.25 4.1134 16.6933 3.5567C16.1366 3 15.2417 3 13.45 3H11.55C9.75825 3 8.86335 3 8.30665 3.5567C7.74995 4.1134 7.74995 5.0083 7.74995 6.8V22M21.05 22V12.025C21.05 10.6902 21.05 10.0233 20.7298 9.54455C20.5911 9.337 20.413 9.15881 20.2054 9.02015C19.7266 8.7 19.0588 8.7 17.725 8.7M3.94995 22V12.025C3.94995 10.6902 3.94995 10.0233 4.2701 9.54455C4.40876 9.337 4.58695 9.15881 4.7945 9.02015C5.2733 8.7 5.94115 8.7 7.27495 8.7" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M12.5001 22.0001V19.1501M10.6001 5.8501H14.4001M10.6001 8.7001H14.4001M10.6001 11.5501H14.4001M10.6001 14.4001H14.4001" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg> Internal
            </a>
            
            <button 
                onclick="window.location.href='{{ route('dts.external') }}'"
                style="
                    padding: 8px 16px;
                    border-radius: 10px;
                    border: none;
                    font-weight: 500;
                    font-size: 14px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    background-color: transparent;
                    color: var(--text-gray);
                    display: flex;
                    align-items: center;
                    gap: 6px;
                "
                @mouseover="this.style.opacity = '0.8'"
                @mouseout="this.style.opacity = '1'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="17" viewBox="0 0 17 21" fill="none" style="flex-shrink: 0;">
                  <path d="M2.89286 19.75C2.32454 19.75 1.77949 19.5276 1.37763 19.1317C0.975765 18.7358 0.75 18.1988 0.75 17.6389V0.75H10.3929L15.75 6.02778V17.6389C15.75 18.1988 15.5242 18.7358 15.1224 19.1317C14.7205 19.5276 14.1755 19.75 13.6071 19.75H2.89286Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M9.32153 0.75V7.08333H15.7501" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                        <path d="M5.03589 11.3055H11.4645M5.03589 15.5278H11.4645" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg> External
            </button>
            
            <a 
                href="{{ route('dts.applications') }}"
                wire:navigate
                style="
                    padding: 8px 16px;
                    border-radius: 10px;
                    border: none;
                    font-weight: 500;
                    font-size: 14px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    background-color: transparent;
                    color: var(--text-gray);
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    text-decoration: none;
                "
                @mouseover="this.style.opacity = '0.8'"
                @mouseout="this.style.opacity = '1'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="15" viewBox="0 0 21 19" fill="none" style="flex-shrink: 0;">
                  <path d="M8.25 7.125C8.25 6.91504 8.32902 6.71367 8.46967 6.56521C8.61032 6.41674 8.80109 6.33333 9 6.33333H15C15.1989 6.33333 15.3897 6.41674 15.5303 6.56521C15.671 6.71367 15.75 6.91504 15.75 7.125C15.75 7.33496 15.671 7.53633 15.5303 7.68479C15.3897 7.83326 15.1989 7.91667 15 7.91667H9C8.80109 7.91667 8.61032 7.83326 8.46967 7.68479C8.32902 7.53633 8.25 7.33496 8.25 7.125ZM9 11.0833H15C15.1989 11.0833 15.3897 10.9999 15.5303 10.8515C15.671 10.703 15.75 10.5016 15.75 10.2917C15.75 10.0817 15.671 9.88034 15.5303 9.73187C15.3897 9.58341 15.1989 9.5 15 9.5H9C8.80109 9.5 8.61032 9.58341 8.46967 9.73187C8.32902 9.88034 8.25 10.0817 8.25 10.2917C8.25 10.5016 8.32902 10.703 8.46967 10.8515C8.61032 10.9999 8.80109 11.0833 9 11.0833ZM21 15.8333C21 16.6732 20.6839 17.4786 20.1213 18.0725C19.5587 18.6664 18.7957 19 18 19H7.5C6.70435 19 5.94129 18.6664 5.37868 18.0725C4.81607 17.4786 4.5 16.6732 4.5 15.8333V3.16667C4.5 2.74674 4.34197 2.34401 4.06066 2.04708C3.77936 1.75015 3.39783 1.58333 3 1.58333C2.60218 1.58333 2.22064 1.75015 1.93934 2.04708C1.65804 2.34401 1.5 2.74674 1.5 3.16667C1.5 3.73469 1.95281 4.11865 1.9575 4.1226C2.08163 4.22343 2.17273 4.36275 2.21804 4.52101C2.26334 4.67927 2.26057 4.84853 2.21011 5.00505C2.15965 5.16156 2.06404 5.29747 1.93668 5.39371C1.80933 5.48995 1.65663 5.54169 1.5 5.54167C1.33782 5.54189 1.18006 5.48592 1.05094 5.38234C0.942187 5.29823 0 4.51349 0 3.16667C0 2.32681 0.316071 1.52136 0.87868 0.927495C1.44129 0.33363 2.20435 0 3 0H15.75C16.5457 0 17.3087 0.33363 17.8713 0.927495C18.4339 1.52136 18.75 2.32681 18.75 3.16667V13.4583H19.5C19.6623 13.4583 19.8202 13.5139 19.95 13.6167C20.0625 13.7018 21 14.4865 21 15.8333ZM8.27438 14.0006C8.32562 13.841 8.42342 13.7025 8.55376 13.6051C8.6841 13.5077 8.84031 13.4563 9 13.4583H17.25V3.16667C17.25 2.74674 17.092 2.34401 16.8107 2.04708C16.5294 1.75015 16.1478 1.58333 15.75 1.58333H5.59594C5.86124 2.06396 6.00069 2.61041 6 3.16667V15.8333C6 16.2533 6.15804 16.656 6.43934 16.9529C6.72065 17.2499 7.10218 17.4167 7.5 17.4167C7.89783 17.4167 8.27936 17.2499 8.56066 16.9529C8.84197 16.656 9 16.2533 9 15.8333C9 15.2653 8.54719 14.8814 8.5425 14.8774C8.41469 14.7809 8.31963 14.6436 8.27136 14.4857C8.22308 14.3279 8.22414 14.1578 8.27438 14.0006ZM19.5 15.8333C19.4906 15.54 19.3834 15.2596 19.1972 15.0417H10.3847C10.46 15.298 10.4982 15.5649 10.4981 15.8333C10.4989 16.3893 10.3602 16.9357 10.0959 17.4167H18C18.3978 17.4167 18.7794 17.2499 19.0607 16.9529C19.342 16.656 19.5 16.2533 19.5 15.8333Z" fill="currentColor"/>
                </svg> Application
            </a>
            
            <a 
                href="{{ route('dts.issuances') }}"
                wire:navigate
                style="
                    padding: 8px 16px;
                    border-radius: 10px;
                    border: none;
                    font-weight: 500;
                    font-size: 14px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    background-color: var(--primary-blue);
                    color: white;
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    text-decoration: none;
                "
                @mouseover="this.style.opacity = '0.8'"
                @mouseout="this.style.opacity = '1'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="17" viewBox="0 0 21 22" fill="none" style="flex-shrink: 0;">
                  <path d="M6.25 3.35718H3.41667C3.04094 3.35718 2.68061 3.50016 2.41493 3.75468C2.14926 4.00919 2 4.35438 2 4.71432V19.6429C2 20.0028 2.14926 20.348 2.41493 20.6025C2.68061 20.8571 3.04094 21 3.41667 21H17.5833C17.9591 21 18.3194 20.8571 18.5851 20.6025C18.8507 20.348 19 20.0028 19 19.6429V4.71432C19 4.35438 18.8507 4.00919 18.5851 3.75468C18.3194 3.50016 17.9591 3.35718 17.5833 3.35718H14.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M9.08325 8.78571H16.1666M9.08325 12.8571H16.1666M9.08325 16.9286H16.1666M4.83325 8.78571H6.24992M4.83325 12.8571H6.24992M4.83325 16.9286H6.24992M7.66659 2H13.3333C13.709 2 14.0693 2.14298 14.335 2.3975C14.6007 2.65201 14.7499 2.99721 14.7499 3.35714C14.7499 3.71708 14.6007 4.06227 14.335 4.31679C14.0693 4.5713 13.709 4.71429 13.3333 4.71429H7.66659C7.29086 4.71429 6.93053 4.5713 6.66485 4.31679C6.39917 4.06227 6.24992 3.71708 6.24992 3.35714C6.24992 2.99721 6.39917 2.65201 6.66485 2.3975C6.93053 2.14298 7.29086 2 7.66659 2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg> Issuances
            </a>
        </div>
        


        <div style="position: relative; width: 100%; max-width: 320px;">
            <input 
                type="text"
                wire:model.live="searchQuery"
                placeholder="Control Number"
                style="
                    width: 100%;
                    padding: 10px 16px 10px 40px;
                    border: 1.5px solid var(--border-gray);
                    border-radius: 8px;
                    font-size: 14px;
                    transition: all 0.3s ease;
                    box-sizing: border-box;
                    margin-left: 30px;
                "
                @focus="this.style.borderColor = 'var(--primary-blue)'; this.style.boxShadow = '0 0 0 3px rgba(37, 99, 235, 0.1)'"
                @blur="this.style.borderColor = 'var(--border-gray)'; this.style.boxShadow = 'none'"
            />
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 25 25" fill="none" style="position: absolute; left: 40px; top: 50%; transform: translateY(-50%); flex-shrink: 0;">
              <path d="M17.1088 17.1091L21.1345 21.1347M3.01904 11.0706C3.01904 13.2059 3.8673 15.2538 5.37722 16.7637C6.88713 18.2736 8.93501 19.1219 11.0703 19.1219C13.2057 19.1219 15.2536 18.2736 16.7635 16.7637C18.2734 15.2538 19.1217 13.2059 19.1217 11.0706C19.1217 8.93525 18.2734 6.88737 16.7635 5.37746C15.2536 3.86755 13.2057 3.01929 11.0703 3.01929C8.93501 3.01929 6.88713 3.86755 5.37722 5.37746C3.8673 6.88737 3.01904 8.93525 3.01904 11.0706Z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
    </div>



    <div class="max-w-full mx-auto" style="background-color: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); overflow: hidden;">
        


        <div style="overflow-x: auto; padding: 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: var(--bg-light-gray); border-bottom: 2px solid var(--border-gray);">
                        <th style="padding-right: 200px; padding-left: 20px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">SUBJECT</th>
                        <th style="padding-right: 200px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">UNIT/COLLEGE</th>
                        <th style="padding: 16px 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">REQUESTOR</th>
                        <th style="padding: 16px 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">CONTROL NO.</th>
                        <th style="padding: 16px 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">DOC TYPE</th>
                        <th style="padding-right: 200px; padding-left: 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">FROM OFFICE</th>
                        <th style="padding: 16px 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">RECEIVED</th>
                        <th style="padding-right: 200px; padding-left: 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">NEXT OFFICE</th>
                        <th style="padding: 16px 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">ACTION NEEDED</th>
                        <th style="padding: 16px 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">ELAPSED DAY</th>
                        <th style="padding: 16px 30px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">STATUS</th>
                        <th style="padding: 16px 30px; text-align: center; font-weight: 600; font-size: 12px; letter-spacing: 0.5px; color: var(--text-gray); text-transform: uppercase; white-space: nowrap;">VIEW TRANSACTION</th>
                    </tr>
                </thead>
                





                <tbody>
                    <tr style="border-bottom: 1px solid var(--border-gray); transition: background-color 0.2s ease;"
                        @mouseover="this.style.backgroundColor = 'var(--bg-hover)'"
                        @mouseout="this.style.backgroundColor = 'white'">
                        <td colspan="12" style="padding: 40px; text-align: center; color: #9CA3AF; font-size: 14px;">
                            No records found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        





        <div style="padding: 16px 32px; background-color: var(--bg-light-gray); border-top: 1px solid var(--border-gray); display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-gray);">
            <span>Total Records: <strong style="color: var(--text-dark);">0</strong></span>
            <div style="display: flex; gap: 12px;">
                <button style="padding: 6px 12px; border: 1px solid var(--border-gray); background-color: white; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;" @mouseover="this.style.backgroundColor = 'var(--bg-light-gray)'" @mouseout="this.style.backgroundColor = 'white'">← Previous</button>
                    <span style="padding: 6px 12px; display: flex; align-items: center;">Page <strong style="margin: 0 4px;">1</strong>of <strong> 1</strong></span>
                <button style="padding: 6px 12px; border: 1px solid var(--border-gray); background-color: white; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;" @mouseover="this.style.backgroundColor = 'var(--bg-light-gray)'" @mouseout="this.style.backgroundColor = 'white'">Next →</button>
            </div>
        </div>
    </div>
</div>