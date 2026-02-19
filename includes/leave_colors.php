<?php
/**
 * Single source of truth for leave type colors and CSS.
 * Used by leaves.php and timesheet.php so styling is identical everywhere.
 */

if (!defined('LEAVE_SICKNESS_BG')) {
    define('LEAVE_SICKNESS_BG', 'rgba(214, 51, 108, 0.2)');
    define('LEAVE_SICKNESS_FG', '#c92a6a');
    define('LEAVE_SICKNESS_SOLID', '#c92a6a');
}
/** Public holiday (timesheet + leaves calendar) – single source */
if (!defined('PUBLIC_HOLIDAY_BG')) {
    define('PUBLIC_HOLIDAY_BG', 'rgba(253, 126, 20, 0.25)');
    define('PUBLIC_HOLIDAY_HEADER_BG', 'rgba(253, 126, 20, 0.35)');
    define('PUBLIC_HOLIDAY_FG', '#9c460d');
}

/** Leave type key for Vacation (normal leave) */
define('LEAVE_TYPE_VACATION', 'Vacation');
/** Leave type key for Sickness */
define('LEAVE_TYPE_SICKNESS', 'Sickness');

/**
 * Map leave type -> frontend color key (Tabler name or 'sickness' for custom class).
 * Pass this to JS so getLeaveColor() uses the same mapping.
 */
$LEAVE_TYPE_COLORS = [
    LEAVE_TYPE_VACATION => 'blue',
    LEAVE_TYPE_SICKNESS => 'sickness',
];

/**
 * @param int|bool $isSickness
 * @return string 'Sickness'|'Vacation'
 */
function get_leave_type($isSickness) {
    return !empty($isSickness) ? LEAVE_TYPE_SICKNESS : LEAVE_TYPE_VACATION;
}

/**
 * Badge class for table (leaves.php): 'badge-sickness' or 'bg-blue-lt'
 * @param int|bool $isSickness
 * @return string
 */
function get_leave_badge_class($isSickness) {
    return !empty($isSickness) ? 'badge-sickness' : 'bg-blue-lt';
}

/**
 * Row background class (leaves.php): 'leave-row-sickness' or ''
 * @param int|bool $isSickness
 * @return string
 */
function get_leave_row_class($isSickness) {
    return !empty($isSickness) ? 'leave-row-sickness' : '';
}

/**
 * Banner class for timesheet collapsed leave block: 'leave-banner-sickness' or 'bg-blue-lt text-blue'
 * @param string $type LEAVE_TYPE_VACATION|LEAVE_TYPE_SICKNESS
 * @return string
 */
function get_leave_banner_class($type) {
    return ($type === LEAVE_TYPE_SICKNESS) ? 'leave-banner-sickness' : 'bg-blue-lt text-blue';
}

/**
 * Returns CSS for all leave-related styles (sickness + calendar). Single source for color values.
 * Include this once in leaves.php and once in timesheet.php (or in a shared layout).
 * @return string HTML <style>...</style>
 */
function leave_colors_styles() {
    $bg = LEAVE_SICKNESS_BG;
    $fg = LEAVE_SICKNESS_FG;
    $solid = LEAVE_SICKNESS_SOLID;
    $holidayBg = defined('PUBLIC_HOLIDAY_BG') ? PUBLIC_HOLIDAY_BG : 'rgba(253, 126, 20, 0.25)';
    $holidayHeaderBg = defined('PUBLIC_HOLIDAY_HEADER_BG') ? PUBLIC_HOLIDAY_HEADER_BG : 'rgba(253, 126, 20, 0.35)';
    $holidayFg = defined('PUBLIC_HOLIDAY_FG') ? PUBLIC_HOLIDAY_FG : '#9c460d';
    return <<<CSS
<style>
/* Leave colors – single source (includes/leave_colors.php) */
.badge-sickness { background: {$bg}; color: {$fg}; }
.leave-row-sickness { background: rgba(214, 51, 108, 0.06); }
.leave-banner-sickness { background: {$bg}; color: {$fg}; }

/* Public holiday – timesheet time entries */
.timesheet-day-public-holiday { background: {$holidayBg} !important; }
.timesheet-day-public-holiday .timesheet-day-header { background: {$holidayHeaderBg} !important; color: {$holidayFg}; font-weight: 600; }

/* leaves.php year calendar – sickness */
.leaves-year-calendar .calendar-day-weekend.calendar-range-sickness .date-item { background: transparent; color: {$fg}; }
.leaves-year-calendar .calendar-day-weekend.calendar-range-other-sickness .date-item { background: {$bg}; color: {$fg}; }
.leaves-year-calendar .calendar-range-sickness .date-item { background: rgba(214, 51, 108, 0.5); color: #fff; font-weight: 600; }
.leaves-year-calendar .calendar-range-sickness.range-start .date-item,
.leaves-year-calendar .calendar-range-sickness.range-end .date-item { background: {$solid}; color: #fff; }
.leaves-year-calendar .calendar-range-other-sickness .date-item { background: {$bg}; color: {$fg}; font-weight: 600; }
</style>
CSS;
}
