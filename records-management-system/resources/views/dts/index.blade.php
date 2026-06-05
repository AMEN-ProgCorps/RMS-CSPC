<x-layouts::dashboard subsystem="Document Tracking System">

    <x-slot:navigation>

        <div id="dts-documents-id" class="button-section-container">
            <div class="button-container" onclick="showButtonSection('dts-documents-id')">
                <div class="button-icon">
                    <img src="{{ asset('icons/sample.svg') }}" alt="Documents Icon">
                </div>
                <div class="button-label">
                    <span>Documents</span>
                </div>
                <div class="show-arrow">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                        <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715
                        9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169
                        9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25
                        8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273
                        7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464"
                        stroke-width="0.5"/>
                    </svg>
                </div>
            </div>
            <div class="functions-container">
                <div class="function-button" onclick="proccedto('#')">Incoming</div>
                <div class="function-button" onclick="proccedto('#')">Outgoing</div>
                <div class="function-button" onclick="proccedto('#')">All Documents</div>
            </div>
        </div>

        <div id="dts-reports-id" class="button-section-container">
            <div class="button-container" onclick="proccedto('#')">
                <div class="button-icon">
                    <img src="{{ asset('icons/sample.svg') }}" alt="Reports Icon">
                </div>
                <div class="button-label">
                    <span>Reports</span>
                </div>
            </div>
        </div>

    </x-slot:navigation>

    <div style="padding: 2rem;">
        <h2>Document Tracking System</h2>
        <p>Welcome, {{ auth()->user()?->username }}.</p>
    </div>

</x-layouts::dashboard>
