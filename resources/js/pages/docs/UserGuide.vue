<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="User Guide" />
    <div class="min-h-screen bg-[#FDFDFC] dark:bg-[#0a0a0a]">
        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-8 flex items-center justify-between">
                <Link
                    href="/"
                    class="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Home
                </Link>
            </div>

            <!-- Content -->
            <article class="prose prose-gray max-w-none dark:prose-invert prose-headings:font-semibold prose-h1:text-2xl prose-h2:text-xl prose-h3:text-lg prose-a:text-blue-600 dark:prose-a:text-blue-400">
                <h1>Track AI PWA — User Guide</h1>
                <p class="lead text-gray-600 dark:text-gray-400">
                    Version: Current (Laravel Cloud Deployment Build)<br />
                    Audience: DPWH Field Engineers, Inspectors, Supervisors, Admins
                </p>

                <hr class="my-8" />

                <h2 id="overview">1. Overview</h2>
                <p>Track AI is a Progressive Web App (PWA) designed for:</p>
                <ul>
                    <li>Field attendance tracking</li>
                    <li>Project-based evidence uploads</li>
                    <li>Progress updates with AI workflow integration</li>
                    <li>Optional Face Login (if enabled and pre-enrolled)</li>
                    <li>Full audit trail logging</li>
                </ul>
                <p>The app works on:</p>
                <ul>
                    <li>Desktop browsers</li>
                    <li>Mobile browsers</li>
                    <li>Installed as a PWA on mobile home screen</li>
                </ul>
                <p><strong>No sign-up is available.</strong> Accounts are provisioned by administrators.</p>

                <hr class="my-8" />

                <h2 id="getting-started">2. Getting Started</h2>
                <h3>2.1 Accessing Track AI</h3>
                <p>Open the deployed URL in your browser. You may:</p>
                <ul>
                    <li>Use it in browser</li>
                    <li>Install it to your device (recommended for field use)</li>
                </ul>
                <p><strong>To install:</strong></p>
                <ul>
                    <li>On mobile: tap browser menu → "Add to Home Screen"</li>
                    <li>On desktop: click install icon in address bar (if supported)</li>
                </ul>

                <hr class="my-8" />

                <h2 id="authentication">3. Authentication</h2>
                <h3>3.1 Login with Username and Password (Primary Method)</h3>
                <ol>
                    <li>Enter your username.</li>
                    <li>Enter your password.</li>
                    <li>Click <strong>Sign In</strong>.</li>
                </ol>
                <p>On success, you will be redirected to the Projects page.</p>

                <h3>3.2 Login with Face (Optional)</h3>
                <p>Face login is available only if your face has been pre-enrolled by your administrator.</p>
                <p><strong>Steps:</strong></p>
                <ol>
                    <li>Enter your username.</li>
                    <li>Click <strong>Login with Face</strong>.</li>
                    <li>Allow camera access if prompted.</li>
                    <li>Center your face within the guide.</li>
                    <li>Tap <strong>Capture</strong>.</li>
                    <li>Confirm and submit.</li>
                </ol>
                <p>If verified, you will be logged in automatically. If not verified, you may retry or use password login.</p>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <strong>Important:</strong> Face login requires internet connection. Face images are not stored in Track AI.
                </p>

                <hr class="my-8" />

                <h2 id="projects">4. Projects</h2>
                <h3>4.1 Projects List</h3>
                <p>After login, you will see your assigned projects displaying:</p>
                <ul>
                    <li>Project name</li>
                    <li>Description</li>
                    <li>Status</li>
                </ul>
                <p>Click a project to access Attendance, Uploads, and Progress updates.</p>

                <hr class="my-8" />

                <h2 id="attendance">5. Attendance</h2>
                <h3>5.1 Check-In</h3>
                <ol>
                    <li>Open project.</li>
                    <li>Go to <strong>Attendance</strong>.</li>
                    <li>Tap <strong>Check In</strong>.</li>
                    <li>Optional: add remarks.</li>
                    <li>Confirm.</li>
                </ol>
                <p>System records: Timestamp, Geo-location (if available), Device context, Audit log entry.</p>

                <h3>5.2 Check-Out</h3>
                <ol>
                    <li>Return to Attendance.</li>
                    <li>Tap <strong>Check Out</strong>.</li>
                    <li>Optional remarks.</li>
                    <li>Confirm.</li>
                </ol>
                <p>You cannot check in twice without checking out.</p>

                <hr class="my-8" />

                <h2 id="uploads">6. Uploads Dashboard</h2>
                <p>Access via: Project → Uploads. This is your evidence dashboard.</p>

                <h3>6.1 Upload List View</h3>
                <p>Each item shows:</p>
                <ul>
                    <li>Thumbnail (image) or icon (PDF)</li>
                    <li>Title, Tags, Remarks preview</li>
                    <li>Status badge: Pending Sync, Uploading, Uploaded, Failed, Deleted</li>
                </ul>
                <p>You can filter by Status, Tag, or Search.</p>

                <h3>6.2 Creating a New Upload</h3>
                <ol>
                    <li>Click <strong>+ New Upload</strong></li>
                    <li>Enter: Title, Document Type, Tags, Remarks</li>
                    <li>Capture or select file.</li>
                    <li>Submit.</li>
                </ol>
                <p>If online, upload begins immediately. If offline, upload is queued (Pending Sync) and will sync automatically when online.</p>

                <h3>6.3 Viewing an Upload</h3>
                <p>Click thumbnail or title to open <strong>Preview Viewer</strong> with large image/PDF, metadata, status, and action buttons.</p>

                <h3>6.4 Editing Metadata</h3>
                <p>From Preview, click <strong>Edit</strong>. Editable fields: Title, Tags, Remarks, Document Type.</p>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Restrictions: If project is closed or upload is referenced in progress submission, editing may be disabled.
                </p>

                <h3>6.5 Deleting Upload</h3>
                <p>From Preview, click <strong>Delete</strong> and confirm. Pending uploads are removed locally. Uploaded files are soft deleted (kept in audit trail).</p>

                <h3>6.6 Retry Failed Upload</h3>
                <p>If status = Failed, click <strong>Retry</strong> to reattempt upload.</p>

                <hr class="my-8" />

                <h2 id="offline">7. Offline Mode</h2>
                <p>Track AI supports offline-first behavior.</p>
                <p><strong>What works offline:</strong></p>
                <ul>
                    <li>Creating uploads (queued)</li>
                    <li>Editing pending uploads</li>
                    <li>Attendance (queued)</li>
                </ul>
                <p><strong>What requires internet:</strong></p>
                <ul>
                    <li>Face login</li>
                    <li>Upload synchronization</li>
                    <li>AI workflow processing</li>
                </ul>
                <p>When offline, you will see Pending Sync badges. Sync happens automatically once connection returns.</p>

                <hr class="my-8" />

                <h2 id="progress">8. Progress Updates</h2>
                <p>Access: Project → Progress</p>
                <p>You may:</p>
                <ul>
                    <li>Submit progress percentage</li>
                    <li>Attach evidence (linked uploads)</li>
                    <li>Add remarks</li>
                </ul>
                <p>Upon submission, AI workflow may be triggered and workflow status is tracked. Uploads referenced in progress updates may become locked.</p>

                <hr class="my-8" />

                <h2 id="audit">9. Audit & Status Tracking</h2>
                <p>Track AI records:</p>
                <ul>
                    <li>Login attempts (including face login attempts)</li>
                    <li>Attendance events</li>
                    <li>Upload events</li>
                    <li>Metadata changes</li>
                    <li>Deletions</li>
                    <li>AI workflow status</li>
                </ul>
                <p>Audit logs are used for compliance, review, and incident investigation. <strong>Biometric images are never stored.</strong></p>

                <hr class="my-8" />

                <h2 id="locking">10. Locking Rules</h2>
                <p>Uploads become read-only when:</p>
                <ul>
                    <li>Project is closed</li>
                    <li>Upload is referenced in approved progress submission</li>
                    <li>Admin locks the project (future feature)</li>
                </ul>
                <p>Locked uploads can be viewed but cannot be edited or deleted.</p>

                <hr class="my-8" />

                <h2 id="best-practices">11. Best Practices for Field Use</h2>
                <ul>
                    <li>Ensure good lighting when capturing images.</li>
                    <li>Use descriptive titles.</li>
                    <li>Add meaningful tags for easier filtering.</li>
                    <li>Sync before leaving site if possible.</li>
                    <li>Use face login only if pre-enrolled and connection is stable.</li>
                </ul>

                <hr class="my-8" />

                <h2 id="troubleshooting">12. Troubleshooting</h2>
                <h3>Face login not working</h3>
                <ul>
                    <li>Check internet connection.</li>
                    <li>Ensure camera permission enabled.</li>
                    <li>Use password login fallback.</li>
                </ul>
                <h3>Upload stuck in Pending</h3>
                <ul>
                    <li>Check connection.</li>
                    <li>Trigger manual sync.</li>
                    <li>Retry if marked Failed.</li>
                </ul>
                <h3>Camera not opening</h3>
                <ul>
                    <li>Allow camera permissions in browser settings.</li>
                    <li>Refresh page.</li>
                </ul>

                <hr class="my-8" />

                <h2 id="security">13. Security & Privacy</h2>
                <ul>
                    <li>No biometric images stored locally in Track AI.</li>
                    <li>Evidence files stored securely in object storage.</li>
                    <li>All sensitive operations are audited.</li>
                    <li>Sessions protected via Laravel authentication.</li>
                </ul>

                <hr class="my-8" />

                <h2 id="roles">14. Roles</h2>
                <h3>Engineer</h3>
                <ul>
                    <li>Attendance</li>
                    <li>Uploads</li>
                    <li>Progress submission</li>
                    <li>Face login (if enabled)</li>
                </ul>
                <h3>Inspector</h3>
                <p>Same as Engineer (depending on assignment)</p>
                <h3>Admin</h3>
                <ul>
                    <li>User provisioning (external)</li>
                    <li>Audit visibility (if enabled)</li>
                </ul>

                <hr class="my-8" />

                <p class="text-center text-sm text-gray-500 dark:text-gray-500">
                    End of User Guide
                </p>
            </article>
        </div>
    </div>
</template>
