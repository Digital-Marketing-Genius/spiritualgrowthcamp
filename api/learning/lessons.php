<?php
/**
 * GET /api/learning/lessons.php?course=spiritual-growth-bootcamp
 * Authorization: Bearer <Firebase ID token>
 *
 * Returns the course lessons only to signed-in, verified Bootcamp students.
 * Lessons are stored privately in sgc-private/learning/<course>.json (never in the public repo).
 */
require __DIR__ . '/_access.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex');

$course = (string)($_GET['course'] ?? '');
if (!preg_match('/^[a-z0-9-]{3,60}$/', $course)) sgc_json(['error' => 'Unknown course.'], 400);

$claims = lp_verify_firebase_token(lp_bearer());
if (!$claims) sgc_json(['error' => 'Please sign in.', 'reason' => 'signin'], 401);
$email = (string)($claims['email'] ?? '');
if (empty($claims['email_verified'])) sgc_json(['error' => 'Please verify your email address.', 'reason' => 'unverified', 'email' => $email], 403);
if (!lp_is_student($email)) {
    sgc_log('learning', 'Access refused (not a student)', ['course' => $course]);
    sgc_json(['error' => 'This course is for Spiritual Growth Bootcamp students.', 'reason' => 'not_student', 'email' => $email], 403);
}

$file = sgc_private_dir() . "/learning/$course.json";
if (!is_file($file)) sgc_json(['error' => 'Course content is not available yet.'], 503);
header('Content-Type: application/json; charset=utf-8');
readfile($file);
exit;
