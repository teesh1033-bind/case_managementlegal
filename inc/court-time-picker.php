<?php
/**
 * Appointment-style time slot picker (30-minute grid, 9:00 AM – 5:30 PM).
 */

function legalpro_appointment_time_slots(): array
{
    $slots = [];
    $startMinutes = 9 * 60;
    $endMinutes = 17 * 60 + 30;

    for ($minutes = $startMinutes; $minutes <= $endMinutes; $minutes += 30) {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $value = sprintf('%02d:%02d', $hours, $mins);
        $slots[$value] = legalpro_format_time_ampm($value);
    }

    return $slots;
}

function legalpro_format_time_ampm(string $timeHm): string
{
    $parts = explode(':', trim($timeHm));
    $hours = isset($parts[0]) ? (int) $parts[0] : 0;
    $minutes = isset($parts[1]) ? $parts[1] : '00';
    $period = $hours >= 12 ? 'PM' : 'AM';
    $displayHours = $hours % 12;
    if ($displayHours === 0) {
        $displayHours = 12;
    }

    return $displayHours . ':' . $minutes . ' ' . $period;
}

function legalpro_normalize_time_hm(?string $timeValue): string
{
    $parsed = legalpro_parse_court_time_input($timeValue);

    return $parsed !== '' ? $parsed : trim((string) $timeValue);
}

/**
 * Parse manual court time entry (24h or 12h with AM/PM).
 */
function legalpro_parse_court_time_input(?string $timeValue): string
{
    $timeValue = trim((string) $timeValue);
    if ($timeValue === '') {
        return '';
    }

    $timeValue = preg_replace('/\s+/', ' ', $timeValue);

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i', $timeValue, $matches)) {
        return '';
    }

    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];
    $period = isset($matches[4]) ? strtoupper($matches[4]) : '';

    if ($period === 'PM' && $hours < 12) {
        $hours += 12;
    } elseif ($period === 'AM' && $hours === 12) {
        $hours = 0;
    }

    if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
        return '';
    }

    return sprintf('%02d:%02d', $hours, $minutes);
}

function legalpro_court_time_min(): string
{
    return '09:00';
}

function legalpro_court_time_max(): string
{
    return '17:30';
}

function legalpro_time_hm_to_minutes(string $timeHm): ?int
{
    $normalized = legalpro_normalize_time_hm($timeHm);
    if ($normalized === '') {
        return null;
    }

    $parts = explode(':', $normalized);

    return ((int) $parts[0] * 60) + (int) $parts[1];
}

function legalpro_is_time_within_court_hours(string $timeHm): bool
{
    $minutes = legalpro_time_hm_to_minutes($timeHm);
    if ($minutes === null) {
        return false;
    }

    $minMinutes = legalpro_time_hm_to_minutes(legalpro_court_time_min());
    $maxMinutes = legalpro_time_hm_to_minutes(legalpro_court_time_max());
    if ($minMinutes === null || $maxMinutes === null) {
        return false;
    }

    return $minutes >= $minMinutes && $minutes <= $maxMinutes;
}

function legalpro_render_court_time_input(string $inputName, string $inputId, string $datalistId, string $selected = ''): string
{
    $selected = legalpro_parse_court_time_input($selected);
    $displayValue = $selected !== '' ? legalpro_format_time_ampm($selected) : '';
    $inputIdEsc = htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8');
    $inputNameEsc = htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8');
    $datalistIdEsc = htmlspecialchars($datalistId, ENT_QUOTES, 'UTF-8');
    $displayEsc = htmlspecialchars($displayValue, ENT_QUOTES, 'UTF-8');

    $presetOptions = '';
    foreach (legalpro_appointment_time_slots() as $value => $label) {
        $presetOptions .= '<option value="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"></option>';
    }

    return '<input type="text" name="' . $inputNameEsc . '" id="' . $inputIdEsc . '" class="form-control legalpro-court-time-input"'
        . ' placeholder="e.g. 9:30 AM or 14:15" autocomplete="off" spellcheck="false"'
        . ' list="' . $datalistIdEsc . '" value="' . $displayEsc . '" required disabled>'
        . '<datalist id="' . $datalistIdEsc . '">' . $presetOptions . '</datalist>'
        . '<small class="text-muted d-block mt-2 legalpro-time-slot-hint">Type the court time manually (for example <strong>9:15 AM</strong>, <strong>2:45 PM</strong>, or <strong>14:15</strong>). Allowed between '
        . htmlspecialchars(legalpro_format_time_ampm(legalpro_court_time_min()), ENT_QUOTES, 'UTF-8')
        . ' and '
        . htmlspecialchars(legalpro_format_time_ampm(legalpro_court_time_max()), ENT_QUOTES, 'UTF-8')
        . '.</small>';
}

function legalpro_render_time_slot_dropdown(string $inputName, string $selectId, string $selected = ''): string
{
    return legalpro_render_court_time_input($inputName, $selectId, $selectId . '_presets', $selected);
}

function legalpro_render_time_slot_picker(string $inputName, string $gridId, string $hiddenInputId, string $selected = ''): string
{
    $slots = legalpro_appointment_time_slots();
    $selected = legalpro_normalize_time_hm($selected);
    $gridIdEsc = htmlspecialchars($gridId, ENT_QUOTES, 'UTF-8');
    $hiddenInputIdEsc = htmlspecialchars($hiddenInputId, ENT_QUOTES, 'UTF-8');
    $inputNameEsc = htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8');
    $selectedEsc = htmlspecialchars($selected, ENT_QUOTES, 'UTF-8');

    $buttons = '';
    $hasSelected = $selected !== '' && isset($slots[$selected]);

    foreach ($slots as $value => $label) {
        $isSelected = $selected === $value;
        $selectedClass = $isSelected ? ' selected' : '';
        $buttons .= '<button type="button" class="legalpro-time-slot-btn available' . $selectedClass . '" data-time="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</button>';
    }

    if ($selected !== '' && !$hasSelected) {
        $buttons .= '<button type="button" class="legalpro-time-slot-btn available selected legalpro-time-slot-btn--custom" data-time="' . $selectedEsc . '">'
            . htmlspecialchars(legalpro_format_time_ampm($selected), ENT_QUOTES, 'UTF-8')
            . '</button>';
    }

    return '<div class="legalpro-time-slot-grid" id="' . $gridIdEsc . '" data-hidden-input="' . $hiddenInputIdEsc . '" role="group" aria-label="Select time">'
        . $buttons
        . '</div>'
        . '<input type="hidden" name="' . $inputNameEsc . '" id="' . $hiddenInputIdEsc . '" value="' . $selectedEsc . '" required>'
        . '<small class="text-muted d-block mt-2 legalpro-time-slot-hint">Green slots are open. Crossed-out gray slots are unavailable on the selected date.</small>';
}

function legalpro_render_time_slot_picker_styles(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    echo '<style>'
        . '.legalpro-time-slot-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.4rem;margin-top:.25rem;}'
        . '.legalpro-time-slot-btn{padding:.45rem .2rem;border-radius:8px;font-size:.75rem;font-weight:600;text-align:center;transition:all .15s ease;line-height:1.2;border:1.5px solid #e2e8f0;color:#94a3b8;background:#f8fafc;cursor:not-allowed;}'
        . '.legalpro-time-slot-btn.available{border-color:#6ee7b7;color:#047857;background:#f0fdf4;cursor:pointer;}'
        . '.legalpro-time-slot-btn.available:hover{border-color:#10b981;background:#dcfce7;}'
        . '.legalpro-time-slot-btn.selected{border-color:#023e8a;background:#023e8a;color:#fff;box-shadow:0 4px 12px rgba(2,62,138,.28);cursor:pointer;}'
        . '.legalpro-time-slot-btn.unavailable{border-color:#e2e8f0;color:#94a3b8;background:#f1f5f9;cursor:not-allowed;opacity:.9;text-decoration:line-through;}'
        . '.legalpro-time-slot-btn--custom{font-style:italic;}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.available{border-color:rgba(45,206,137,.45);color:#6ee7b7;background:rgba(45,206,137,.12);}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.available:hover{border-color:#2dce89;background:rgba(45,206,137,.2);}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.selected{border-color:#4a90d9;background:#023e8a;color:#fff;}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.unavailable{border-color:rgba(255,255,255,.08);color:#64748b;background:rgba(255,255,255,.04);}'
        . '.legalpro-time-slot-select option.legalpro-time-available{color:#047857;font-weight:600;}'
        . '.legalpro-time-slot-select option.legalpro-time-unavailable{color:#94a3b8;text-decoration:line-through;}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-select option.legalpro-time-available{color:#6ee7b7;}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-select option.legalpro-time-unavailable{color:#64748b;}'
        . '.legalpro-court-time-input{font-variant-numeric:tabular-nums;}'
        . '.legalpro-court-time-input:disabled{opacity:.65;cursor:not-allowed;}'
        . '.legalpro-court-time-input::placeholder{color:#94a3b8;opacity:1;}'
        . 'body.legalpro-dark-mode .legalpro-court-time-input{background:#1e293b;border-color:rgba(255,255,255,.12);color:#e2e8f0;}'
        . 'body.legalpro-dark-mode .legalpro-court-time-input::placeholder{color:#64748b;}'
        . 'body.legalpro-dark-mode .legalpro-court-time-input:disabled{background:rgba(255,255,255,.04);}'
        . '</style>';
}

function legalpro_render_time_slot_picker_script(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    echo '<script>'
        . '(function(){'
        . 'function formatTimeLabel(timeValue){'
        . 'if(!timeValue){return "";}'
        . 'var parts=timeValue.split(":");'
        . 'var hours=parseInt(parts[0],10);'
        . 'var minutes=parts[1]||"00";'
        . 'var period=hours>=12?"PM":"AM";'
        . 'var displayHours=hours%12;if(displayHours===0){displayHours=12;}'
        . 'return displayHours+":"+minutes+" "+period;'
        . '}'
        . 'function normalizeTimeValue(timeValue){'
        . 'if(!timeValue){return "";}'
        . 'var parts=timeValue.split(":");'
        . 'if(parts.length<2){return timeValue;}'
        . 'return String(parts[0]).padStart(2,"0")+":"+String(parts[1]).padStart(2,"0");'
        . '}'
        . 'function applySlotAvailabilityState(btn,isBooked){'
        . 'btn.classList.toggle("unavailable",isBooked);'
        . 'btn.classList.toggle("available",!isBooked);'
        . 'btn.disabled=isBooked;'
        . 'if(isBooked){btn.classList.remove("selected");}'
        . '}'
        . 'function notifyHiddenTimeChange(hidden){'
        . 'if(!hidden){return;}'
        . 'hidden.dispatchEvent(new Event("input",{bubbles:true}));'
        . 'hidden.dispatchEvent(new Event("change",{bubbles:true}));'
        . '}'
        . 'window.updateLegalproTimeSlotAvailability=function(gridId,hiddenId,bookedTimes,options){'
        . 'options=options||{};'
        . 'var grid=document.getElementById(gridId);'
        . 'var hidden=document.getElementById(hiddenId);'
        . 'if(!grid||!hidden){return;}'
        . 'var bookedSet={};'
        . '(bookedTimes||[]).forEach(function(timeValue){bookedSet[normalizeTimeValue(timeValue)]=true;});'
        . 'var currentSelected=normalizeTimeValue(hidden.value);'
        . 'grid.querySelectorAll(".legalpro-time-slot-btn:not(.legalpro-time-slot-btn--custom)").forEach(function(btn){'
        . 'var slotTime=normalizeTimeValue(btn.getAttribute("data-time")||"");'
        . 'applySlotAvailabilityState(btn,!!bookedSet[slotTime]);'
        . '});'
        . 'if(currentSelected&&bookedSet[currentSelected]){hidden.value="";'
        . 'grid.querySelectorAll(".legalpro-time-slot-btn.selected").forEach(function(btn){btn.classList.remove("selected");});'
        . 'notifyHiddenTimeChange(hidden);'
        . '}else if(options.preserveSelection!==false&&currentSelected){'
        . 'var matched=grid.querySelector(\'.legalpro-time-slot-btn[data-time="\'+currentSelected+\'"]\');'
        . 'if(matched&&!matched.classList.contains("unavailable")){matched.classList.add("selected");}'
        . '}'
        . '};'
        . 'function bindTimeSlotGrid(gridId){'
        . 'var grid=document.getElementById(gridId);'
        . 'if(!grid||grid.dataset.bound==="1"){return;}'
        . 'grid.dataset.bound="1";'
        . 'grid.addEventListener("click",function(event){'
        . 'var btn=event.target.closest(".legalpro-time-slot-btn");'
        . 'if(!btn||!grid.contains(btn)){return;}'
        . 'if(btn.classList.contains("unavailable")||btn.disabled){return;}'
        . 'event.preventDefault();'
        . 'grid.querySelectorAll(".legalpro-time-slot-btn.selected").forEach(function(node){node.classList.remove("selected");});'
        . 'btn.classList.add("selected");'
        . 'var hiddenId=grid.getAttribute("data-hidden-input");'
        . 'if(hiddenId){var hidden=document.getElementById(hiddenId);if(hidden){hidden.value=btn.getAttribute("data-time")||"";notifyHiddenTimeChange(hidden);}}'
        . '});'
        . '}'
        . 'window.updateLegalproTimeSlotDropdown=function(selectId,bookedTimes,options){'
        . 'options=options||{};'
        . 'var select=document.getElementById(selectId);'
        . 'if(!select){return;}'
        . 'var bookedSet={};'
        . '(bookedTimes||[]).forEach(function(timeValue){bookedSet[normalizeTimeValue(timeValue)]=true;});'
        . 'var currentSelected=normalizeTimeValue(select.value);'
        . 'select.querySelectorAll("option.legalpro-time-option").forEach(function(option){'
        . 'var slotTime=normalizeTimeValue(option.value);'
        . 'if(!slotTime){return;}'
        . 'var isBooked=!!bookedSet[slotTime];'
        . 'option.disabled=isBooked;'
        . 'option.classList.toggle("legalpro-time-unavailable",isBooked);'
        . 'option.classList.toggle("legalpro-time-available",!isBooked);'
        . '});'
        . 'if(currentSelected&&bookedSet[currentSelected]){'
        . 'select.value="";'
        . 'select.dispatchEvent(new Event("change",{bubbles:true}));'
        . '}else if(options.preserveSelection!==false&&currentSelected){'
        . 'select.value=currentSelected;'
        . '}'
        . '};'
        . 'window.setLegalproTimeSlotDropdownSelection=function(selectId,timeValue,bookedTimes){'
        . 'var select=document.getElementById(selectId);'
        . 'if(!select){return;}'
        . 'var normalized=normalizeTimeValue(timeValue);'
        . 'if(Array.isArray(bookedTimes)){updateLegalproTimeSlotDropdown(selectId,bookedTimes,{preserveSelection:false});}'
        . 'if(normalized&&!select.querySelector(\'option[value="\'+normalized+\'"]\')){'
        . 'var custom=document.createElement("option");'
        . 'custom.value=normalized;'
        . 'custom.textContent=formatTimeLabel(normalized);'
        . 'custom.className="legalpro-time-option legalpro-time-available";'
        . 'select.appendChild(custom);'
        . '}'
        . 'select.value=normalized||"";'
        . 'select.dispatchEvent(new Event("change",{bubbles:true}));'
        . '};'
        . 'window.setLegalproTimeSlotSelection=function(gridId,hiddenId,timeValue,bookedTimes){'
        . 'var grid=document.getElementById(gridId);'
        . 'var hidden=document.getElementById(hiddenId);'
        . 'if(!grid||!hidden){return;}'
        . 'bindTimeSlotGrid(gridId);'
        . 'var normalized=normalizeTimeValue(timeValue);'
        . 'hidden.value=normalized;'
        . 'grid.querySelectorAll(".legalpro-time-slot-btn.selected").forEach(function(node){node.classList.remove("selected");});'
        . 'grid.querySelectorAll(".legalpro-time-slot-btn--custom").forEach(function(node){node.remove();});'
        . 'if(Array.isArray(bookedTimes)){updateLegalproTimeSlotAvailability(gridId,hiddenId,bookedTimes,{preserveSelection:false});}'
        . 'var matched=grid.querySelector(\'.legalpro-time-slot-btn[data-time="\'+normalized+\'"]\');'
        . 'if(matched&&!matched.classList.contains("unavailable")){matched.classList.add("selected");return;}'
        . 'if(!normalized){return;}'
        . 'var custom=document.createElement("button");'
        . 'custom.type="button";'
        . 'custom.className="legalpro-time-slot-btn available selected legalpro-time-slot-btn--custom";'
        . 'custom.setAttribute("data-time",normalized);'
        . 'custom.textContent=formatTimeLabel(normalized);'
        . 'grid.appendChild(custom);'
        . '};'
        . 'document.addEventListener("DOMContentLoaded",function(){'
        . 'document.querySelectorAll(".legalpro-time-slot-grid").forEach(function(grid){bindTimeSlotGrid(grid.id);});'
        . '});'
        . '})();'
        . '</script>';
}

