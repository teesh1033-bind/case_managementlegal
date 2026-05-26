#!/usr/bin/env python3
"""Remove Git merge conflict markers from Case Management files."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

CONFLICT_RE = re.compile(
    r"^<<<<<<< HEAD\n(.*?)^=======\n(.*?)^>>>>>>> [^\n]+\n",
    re.MULTILINE | re.DOTALL,
)


def keep_ours(text: str) -> str:
    return CONFLICT_RE.sub(lambda m: m.group(1), text)


def keep_theirs(text: str) -> str:
    return CONFLICT_RE.sub(lambda m: m.group(2), text)


def resolve(path: Path, strategy: str) -> bool:
    text = path.read_text(encoding="utf-8", errors="replace")
    if "<<<<<<< HEAD" not in text:
        return False
    if strategy == "ours":
        new_text = keep_ours(text)
    elif strategy == "theirs":
        new_text = keep_theirs(text)
    else:
        raise ValueError(strategy)
    path.write_text(new_text, encoding="utf-8", newline="\n")
    return True


def main() -> None:
    theirs = [
        "pages/lawyer-appointments.php",
        "pages/lawyer-availability.php",
        "pages/appointments.php",
    ]
    ours = [
        "pages/lawyers.php",
        "pages/client-dashboard.php",
        "pages/client-appointments.php",
        "assets/css/legalpro-client-portal.css",
        "assets/css/app-font-montserrat.css",
    ]

    changed = []
    for rel in theirs:
        p = ROOT / rel
        if p.exists() and resolve(p, "theirs"):
            changed.append(rel + " (theirs)")

    for rel in ours:
        p = ROOT / rel
        if p.exists() and resolve(p, "ours"):
            changed.append(rel + " (ours)")

    # client-appointments: re-apply unavailable-slot block (theirs logic) after ours sweep
    ca = ROOT / "pages/client-appointments.php"
    if ca.exists():
        text = ca.read_text(encoding="utf-8", errors="replace")
        old_block = """                if (!empty($lawyer_id)) {
                    $stmt = $pdo->prepare(\"
                        SELECT COUNT(*) FROM lawyer_time_slots
                        WHERE lawyer_id = ? AND slot_type = 'available'
                    \");"""
        new_block = """                if (!empty($lawyer_id)) {
                    $dayOfWeek = strtolower(date('l', strtotime($appointment_date)));
                    $requestedTime = $appointment_time . ':00';

                    $stmt = $pdo->prepare(\"
                        SELECT COUNT(*) FROM lawyer_time_slots
                        WHERE lawyer_id = ? AND day_of_week = ? AND slot_type = 'unavailable'
                        AND start_time <= ? AND end_time > ?
                    \");
                    $stmt->execute([$lawyer_id, $dayOfWeek, $requestedTime, $requestedTime]);
                    if ((int) $stmt->fetchColumn() > 0) {
                        $availabilityCheckPassed = false;
                        $message = 'Selected time is marked unavailable by the lawyer. Please choose another slot.';
                        $messageType = 'danger';
                    }

                    if ($availabilityCheckPassed) {
                    $stmt = $pdo->prepare(\"
                        SELECT COUNT(*) FROM lawyer_time_slots
                        WHERE lawyer_id = ? AND slot_type = 'available'
                    \");"""
        if old_block in text and "slot_type = 'unavailable'" not in text:
            text = text.replace(old_block, new_block, 1)
            # Close extra if after availability block
            text = text.replace(
                """                        }
                    }
                }

                if ($availabilityCheckPassed) {""",
                """                        }
                    }
                    }
                }

                if ($availabilityCheckPassed) {""",
                1,
            )
            ca.write_text(text, encoding="utf-8", newline="\n")
            changed.append("client-appointments.php (unavailable patch)")

        # JS: unavailable slots in dropdown
        if "unavailableSlots" not in text:
            text = ca.read_text(encoding="utf-8", errors="replace")
            text = text.replace(
                "            const availableSlots = daySlots.filter(function(slot) { return slot.type === 'available'; });\n",
                "            const availableSlots = daySlots.filter(function(slot) { return slot.type === 'available'; });\n"
                "            const unavailableSlots = daySlots.filter(function(slot) { return slot.type === 'unavailable'; });\n",
                1,
            )
            # After "if (availableSlots.length === 0) {" block enable-all section, add unavailable disable
            needle = """                    timeOptions.forEach(option => {
                        option.classList.remove('text-success', 'font-weight-bold');
                        option.disabled = false;
                    });
                    return;
                }"""
            replacement = """                    timeOptions.forEach(option => {
                        option.classList.remove('text-success', 'font-weight-bold');
                        option.disabled = false;
                    });
                    unavailableSlots.forEach(slot => {
                        const start = slot.start_time.substring(0, 5);
                        const end = slot.end_time.substring(0, 5);
                        timeOptions.forEach(option => {
                            const t = option.value;
                            if (t >= start && t < end) {
                                option.disabled = true;
                                option.classList.remove('text-success', 'font-weight-bold');
                            }
                        });
                    });
                    return;
                }"""
            if needle in text and "unavailableSlots.forEach" not in text:
                text = text.replace(needle, replacement, 1)

            # In available-slots branch, block unavailable overlaps
            needle2 = """                    if (isAvailable) {
                        option.classList.add('text-success', 'font-weight-bold');
                        option.disabled = false;"""
            replacement2 = """                    let isBlocked = false;
                    unavailableSlots.forEach(slot => {
                        const start = slot.start_time.substring(0, 5);
                        const end = slot.end_time.substring(0, 5);
                        if (timeValue >= start && timeValue < end) {
                            isBlocked = true;
                        }
                    });

                    if (isAvailable && !isBlocked) {
                        option.classList.add('text-success', 'font-weight-bold');
                        option.disabled = false;"""
            if needle2 in text and "isBlocked" not in text:
                text = text.replace(needle2, replacement2, 1)

            ca.write_text(text, encoding="utf-8", newline="\n")

    print("Updated:", *changed, sep="\n  ")


if __name__ == "__main__":
    main()
