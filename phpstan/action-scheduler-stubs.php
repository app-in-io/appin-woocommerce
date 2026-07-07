<?php

/**
 * Minimal Action Scheduler stubs (bundled with WooCommerce at runtime).
 * Only the functions this plugin actually calls — scanning the full
 * woocommerce-packages-stubs.php exhausts PHPStan's memory.
 */

/**
 * @param  int  $timestamp
 * @param  string  $hook
 * @param  array<int, mixed>  $args
 * @param  string  $group
 * @return int
 */
function as_schedule_single_action($timestamp, $hook, $args = [], $group = '', $unique = false, $priority = 10) {}

/**
 * @param  string  $hook
 * @param  array<int, mixed>  $args
 * @param  string  $group
 */
function as_unschedule_all_actions($hook, $args = [], $group = ''): void {}
