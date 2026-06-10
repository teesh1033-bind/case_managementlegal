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
    $timeValue = trim((string) $timeValue);
    if ($timeValue === '') {
        return '';
    }

    $parts = explode(':', $timeValue);
    if (count($parts) < 2) {
        return $timeValue;
    }

    return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
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
        . '<small class="text-muted d-block mt-2 legalpro-time-slot-hint">Green slots are open. Gray slots are already booked on the selected date.</small>';
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
        . '.legalpro-time-slot-btn.selected{border-color:#5e72e4;background:#5e72e4;color:#fff;box-shadow:0 4px 12px rgba(94,114,228,.28);cursor:pointer;}'
        . '.legalpro-time-slot-btn.unavailable{border-color:#e2e8f0;color:#94a3b8;background:#f1f5f9;cursor:not-allowed;opacity:.9;}'
        . '.legalpro-time-slot-btn--custom{font-style:italic;}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.available{border-color:rgba(45,206,137,.45);color:#6ee7b7;background:rgba(45,206,137,.12);}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.available:hover{border-color:#2dce89;background:rgba(45,206,137,.2);}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.selected{border-color:#9aaeff;background:#5e72e4;color:#fff;}'
        . 'body.legalpro-dark-mode .legalpro-time-slot-btn.unavailable{border-color:rgba(255,255,255,.08);color:#64748b;background:rgba(255,255,255,.04);}'
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
        . 'if(hiddenId){var hidden=document.getElementById(hiddenId);if(hidden){hidden.value=btn.getAttribute("data-time")||"";}}'
        . '});'
        . '}'
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
