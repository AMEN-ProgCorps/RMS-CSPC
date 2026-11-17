{{--
  DCS COMPONENT: Floating / draggable calendar (dashboard only; opened from welcome date trigger).
  FILE: resources/views/components/dcs/floating-calendar.blade.php
--}}
@php
    $showFloatingCalendar = auth()->check()
        && request()->routeIs('dcs', 'dcs.dashboard')
        && !\App\Helpers\RegisterQueryHelper::isLimitedDcsUser()
        && !\App\Helpers\RegisterQueryHelper::isDcsPathPending();
    $year = (int) now('Asia/Manila')->year;
    $floatHolidays = $showFloatingCalendar
        ? \App\Helpers\CalendarHelper::philippineHolidays($year, $year + 1)
        : [];
    $dashboardUrl = route('dcs', absolute: false);
@endphp

@if($showFloatingCalendar)
    <script>
        window.__DCS_DASH_BOOT = window.__DCS_DASH_BOOT || {};
        window.__DCS_DASH_BOOT.holidays = @json($floatHolidays);
        window.__DCS_DASH_URL = @json($dashboardUrl);
    </script>

    <div
        class="dcs-float-cal"
        wire:ignore
        x-data="dcsDashboardCalendar(@js(['holidays' => $floatHolidays, 'dashboardUrl' => $dashboardUrl]))"
        @dcs-calendar-toggle.window="toggleCalendar()"
        @dcs-calendar-open.window="calendarOpen = true"
        @dcs-calendar-close.window="closeCalendar()"
    >
        <div
            class="dcs-float-cal-panel"
            x-show="calendarOpen"
            x-cloak
            :class="{ 'is-dragging': floatDragging }"
            :style="floatPanelStyle"
            @click.stop
        >
            <div
                class="dcs-float-cal-chrome"
                data-float-handle
                @mousedown="startFloatDrag($event)"
                @touchstart="startFloatDrag($event)"
            >
                <div class="dcs-float-cal-chrome-title">
                    <i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>
                    <span>Calendar</span>
                </div>
                <div class="dcs-float-cal-chrome-actions">
                    <button type="button" class="dcs-float-cal-icon-btn" title="Close" @click.stop="closeCalendar()">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="dcs-float-cal-body">
                <div class="widget calendar-widget white-card">
                    <div class="calendar-header">
                        <button
                            type="button"
                            class="cal-title-btn"
                            @click="toggleJumpPicker()"
                            :aria-expanded="jumpOpen.toString()"
                            title="Jump to month or year"
                        >
                            <h3 x-text="title"></h3>
                            <i class="fa-solid fa-caret-down" :class="{ 'is-open': jumpOpen }"></i>
                        </button>
                        <div class="cal-nav">
                            <button type="button" x-on:click="changeMonth(-1)"><i class="fa-solid fa-chevron-left"></i></button>
                            <button type="button" x-on:click="goToday()" title="Today"><i class="fa-regular fa-calendar-check"></i></button>
                            <button type="button" x-on:click="changeMonth(1)"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <div class="cal-jump" x-show="jumpOpen" x-cloak @click.stop>
                        <label class="cal-jump-field">
                            <span>Month</span>
                            <select class="cal-jump-select" x-model.number="month">
                                <template x-for="(name, idx) in months" :key="name">
                                    <option :value="idx" x-text="name" :selected="month === idx"></option>
                                </template>
                            </select>
                        </label>
                        <label class="cal-jump-field">
                            <span>Year</span>
                            <select class="cal-jump-select" x-model.number="year">
                                <template x-for="y in yearOptions" :key="y">
                                    <option :value="y" x-text="y" :selected="year === y"></option>
                                </template>
                            </select>
                        </label>
                        <label class="cal-jump-field cal-jump-field-date">
                            <span>Go to date</span>
                            <input
                                type="date"
                                class="cal-jump-date"
                                :value="jumpDateValue"
                                @change="jumpToDate($event.target.value)"
                            >
                        </label>
                    </div>
                    <div class="weekdays">
                        <div>S</div><div>M</div><div>T</div><div>W</div><div>T</div><div>F</div><div>S</div>
                    </div>
                    <div class="calendar-grid">
                        <template x-for="cell in cells" :key="cell.iso + cell.outside">
                            <div class="cal-cell" :class="{ 'out-month': cell.outside }" x-on:click="openDay(cell.iso)">
                                <div class="day-num"
                                    :class="{ today: cell.today && !cell.colors.length, holiday: cell.holiday && !cell.colors.length }"
                                    :style="cell.colors.length ? ('background-color:' + cell.colors[0] + ';color:#fff;font-weight:700') : ''"
                                    x-text="cell.day"></div>
                                <div class="day-markers">
                                    <span class="day-marker holiday" x-show="cell.holiday"></span>
                                    <template x-for="(color, idx) in cell.colors" :key="idx">
                                        <span class="day-marker event" x-show="idx > 0" :style="'background-color:' + color"></span>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="cal-legend">
                        <span class="legend-item"><span class="dot holiday-dot"></span> Holiday</span>
                        <span class="legend-item"><span class="dot event-dot"></span> Event</span>
                        <button type="button" class="btn-mini" x-on:click="openAdd(todayIso())">+ Add Event</button>
                    </div>
                </div>

                <div class="widget upcoming-widget white-card" :class="{ 'is-collapsed': !eventsOpen }">
                    <button
                        type="button"
                        class="widget-header is-events-toggle"
                        @click="toggleEventsDropdown()"
                        :aria-expanded="eventsOpen.toString()"
                    >
                        <h3>Events</h3>
                        <i class="fa-solid fa-chevron-down dcs-float-cal-events-chevron" :class="{ 'is-open': eventsOpen }"></i>
                        <span class="badge" x-text="filteredUpcoming.length"></span>
                    </button>
                    <div class="ev-category-pills" x-show="eventsOpen && categories.length > 0" x-cloak>
                        <button type="button" class="ev-cat-pill" :class="{ 'is-active': eventCategoryFilter === '' }" @click="eventCategoryFilter = ''">All</button>
                        <template x-for="cat in categories" :key="cat.id">
                            <button
                                type="button"
                                class="ev-cat-pill is-category"
                                :class="{ 'is-active': String(eventCategoryFilter) === String(cat.id) }"
                                :style="{ '--event-category-color': categoryColor(cat) }"
                                @click="eventCategoryFilter = cat.id"
                                x-text="cat.name"
                            ></button>
                        </template>
                    </div>
                    <div class="upcoming-list" x-show="eventsOpen" x-cloak>
                        <template x-if="filteredUpcoming.length === 0">
                            <div class="upcoming-empty">
                                <i class="fa-regular fa-calendar-check"></i>
                                <span x-text="eventCategoryFilter ? 'No events in this category' : 'No events yet'"></span>
                            </div>
                        </template>
                        <template x-for="ev in filteredUpcoming" :key="ev.id">
                            <div class="upcoming-item" x-on:click="openDay(ev.date)">
                                <div class="upcoming-event-dot" :style="'background-color:' + (ev.color || '#0d2a7a')"></div>
                                <div class="upcoming-info">
                                    <div class="upcoming-title-row">
                                        <div class="title" x-text="ev.title"></div>
                                    </div>
                                    <div class="time" x-text="eventListMeta(ev)"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <template x-teleport="body">
            <div class="dash-calendar-portal" x-show="modal !== null" x-cloak @keydown.escape.window="modal = null">
                <div class="dash-calendar-backdrop" @click="modal = null"></div>
                <div class="dash-event-layer" x-cloak x-bind:style="modal !== null ? 'display:flex' : 'display:none'">
                    <div class="dash-event-panel" @click.stop>
                    <template x-if="modal === 'delete'">
                        <div class="ev-modal">
                            <div class="ev-modal-top">
                                <div class="ev-modal-icon is-delete"><i class="fa-solid fa-trash-can"></i></div>
                                <button type="button" class="ev-modal-close" x-on:click="cancelDeleteEvent()"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <h3 class="ev-modal-title">Delete event?</h3>
                            <p class="ev-modal-desc" x-text="pendingDeleteTitle ? ('“' + pendingDeleteTitle + '” will be removed from the calendar.') : 'This event will be removed from the calendar.'"></p>
                            <div class="ev-actions-row">
                                <button type="button" class="ev-btn ev-btn-ghost" x-on:click="cancelDeleteEvent()">Cancel</button>
                                <button type="button" class="ev-btn ev-btn-danger" x-on:click="confirmRemoveEvent()" :disabled="saving">
                                    <i class="fa-solid fa-trash-can"></i>
                                    <span x-text="saving ? 'Deleting…' : 'Delete'"></span>
                                </button>
                            </div>
                        </div>
                    </template>
                    <template x-if="modal === 'day'">
                        <div class="ev-modal">
                            <div class="ev-modal-top">
                                <div class="ev-modal-icon is-view"><i class="fa-regular fa-calendar"></i></div>
                                <button type="button" class="ev-modal-close" x-on:click="modal = null"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <h3 class="ev-modal-title" x-text="displayDate(activeIso)"></h3>
                            <p class="ev-modal-desc" x-text="dayEvents.length + ' event' + (dayEvents.length === 1 ? '' : 's') + ' scheduled'"></p>
                            <div class="ev-holiday" x-show="holidayName(activeIso)">
                                <i class="fa-solid fa-umbrella-beach"></i><span x-text="holidayName(activeIso)"></span>
                            </div>
                            <div class="ev-card-list">
                                <template x-if="dayEvents.length === 0">
                                    <div class="ev-empty"><i class="fa-regular fa-calendar"></i><span>No events scheduled</span></div>
                                </template>
                                <template x-for="ev in dayEvents" :key="ev.id">
                                    <div class="ev-card">
                                        <div class="ev-card-color" :style="'background:' + (ev.color || '#0d2a7a')"></div>
                                        <div class="ev-card-body">
                                            <div class="ev-card-top">
                                                <div class="ev-card-title-wrap">
                                                    <div class="ev-card-title" x-text="ev.title"></div>
                                                </div>
                                                <div class="ev-card-btns" x-show="!ev.readonly">
                                                    <button type="button" class="ev-card-btn" x-on:click="openEdit(ev.id)"><i class="fa-solid fa-pen"></i></button>
                                                    <button type="button" class="ev-card-btn ev-card-btn-danger" x-on:click="askDeleteEvent(ev.id)"><i class="fa-solid fa-trash-can"></i></button>
                                                </div>
                                            </div>
                                            <div class="ev-card-time">
                                                <i class="fa-regular fa-clock"></i>
                                                <span x-text="formatTime(ev.startTime) + ' — ' + formatTime(ev.endTime)"></span>
                                            </div>
                                            <div class="ev-card-time" x-show="ev.category_name">
                                                <i class="fa-solid fa-tag"></i>
                                                <span x-text="ev.category_name"></span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <div class="ev-actions-row">
                                <button type="button" class="ev-btn ev-btn-primary" style="width:100%;" x-on:click="openAdd(activeIso)">
                                    <i class="fa-solid fa-plus"></i> Add Event
                                </button>
                            </div>
                        </div>
                    </template>
                    <template x-if="modal === 'form'">
                        <div class="ev-modal">
                            <div class="ev-modal-top">
                                <div class="ev-modal-icon" :class="editingId ? 'is-edit' : 'is-add'">
                                    <i class="fa-solid" :class="editingId ? 'fa-pen' : 'fa-plus'"></i>
                                </div>
                                <button type="button" class="ev-modal-close" x-on:click="openDay(form.date)"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <h3 class="ev-modal-title" x-text="editingId ? 'Edit Event' : 'New Event'"></h3>
                            <p class="ev-modal-desc" x-text="displayDate(form.date)"></p>
                            <form action="#" method="post" @submit.prevent="saveForm">
                                <div class="ev-field">
                                    <label class="ev-label">Title</label>
                                    <input class="ev-input" x-model="form.title" required autocomplete="off">
                                </div>
                                <div class="ev-field">
                                    <label class="ev-label">Category</label>
                                    <div class="ev-cat-select-row">
                                        <select
                                            class="ev-input"
                                            :key="'ev-cat-' + String(editingId ?? 'new')"
                                            x-model="form.category_id"
                                            required
                                        >
                                            <option value="">Select category</option>
                                            <template x-for="cat in categories" :key="cat.id">
                                                <option
                                                    :value="String(cat.id)"
                                                    :selected="String(form.category_id) === String(cat.id)"
                                                    x-text="cat.name"
                                                ></option>
                                            </template>
                                        </select>
                                        <button type="button" class="ev-cat-icon-btn" title="Add category" :class="{ 'is-open': addingCategory }" @click="toggleAddCategory()">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                        <button type="button" class="ev-cat-icon-btn ev-cat-row-del" title="Delete selected category" x-show="form.category_id" @click="removeSelectedCategory()">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                    <div class="ev-cat-add" x-show="addingCategory" x-cloak>
                                        <label class="ev-color-picker" title="Category color">
                                            <span>Color</span>
                                            <input type="color" x-model="newCategoryColor" aria-label="Category color">
                                        </label>
                                        <input class="ev-input" x-ref="newCatInput" x-model="newCategory" placeholder="Category name" autocomplete="off" @keydown.enter.prevent="addCategory">
                                        <button type="button" class="ev-btn ev-btn-ghost" @click="addCategory">Add</button>
                                    </div>
                                    <div class="ev-cat-add" x-show="form.category_id && !addingCategory" x-cloak>
                                        <label class="ev-color-picker" title="Update category color">
                                            <span>Color</span>
                                            <input
                                                type="color"
                                                :value="selectedCategoryColor"
                                                @input="updateSelectedCategoryColor($event.target.value)"
                                                aria-label="Update category color"
                                            >
                                        </label>
                                        <span class="ev-cat-color-hint">Change color for the selected category</span>
                                    </div>
                                </div>
                                <div class="ev-field">
                                    <label class="ev-label">Date</label>
                                    <input type="date" class="ev-input" x-model="form.date" required>
                                </div>
                                <div class="ev-time-row">
                                    <div class="ev-field ev-field-half">
                                        <label class="ev-label">Start</label>
                                        <input type="time" class="ev-input" x-model="form.startTime" required>
                                    </div>
                                    <div class="ev-field ev-field-half">
                                        <label class="ev-label">End</label>
                                        <input type="time" class="ev-input" x-model="form.endTime" required>
                                    </div>
                                </div>
                                <div class="ev-field">
                                    <label class="ev-label">Notes <span class="ev-optional">Optional</span></label>
                                    <textarea class="ev-textarea" rows="2" x-model="form.description"></textarea>
                                </div>
                                <div class="ev-actions-row">
                                    <button type="button" class="ev-btn ev-btn-ghost" x-on:click="openDay(form.date)">Cancel</button>
                                    <button type="submit" class="ev-btn ev-btn-primary">
                                        <i class="fa-solid" :class="editingId ? 'fa-check' : 'fa-plus'"></i>
                                        <span x-text="editingId ? 'Save' : 'Create'"></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </template>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
