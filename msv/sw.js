// Service Worker for PWA with Push Notifications
// Version: 2.0.0

const CACHE_NAME = 'gsa-pwa-v2.0.0';
const urlsToCache = [
    '/gsa/',
    '/gsa/index.php',
    '/gsa/assets/css/style.css',
    '/gsa/assets/js/app.js',
    '/gsa/assets/logo-192.png',
    '/gsa/assets/logo-512.png',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap'
];

// ============================================================
// INSTALL & ACTIVATE
// ============================================================

self.addEventListener('install', event => {
    console.log('[Service Worker] Installing...');
    
    // Skip waiting to activate immediately
    self.skipWaiting();
    
    // Cache core assets
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            console.log('[Service Worker] Caching app shell');
            return cache.addAll(urlsToCache);
        }).catch(err => {
            console.error('[Service Worker] Cache failed:', err);
        })
    );
});

self.addEventListener('activate', event => {
    console.log('[Service Worker] Activating...');
    
    // Claim clients so the SW takes control immediately
    event.waitUntil(clients.claim());
    
    // Delete old caches
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[Service Worker] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});

// ============================================================
// FETCH - Network First with Cache Fallback
// ============================================================

self.addEventListener('fetch', event => {
    // Skip non-GET requests and external APIs
    if (event.request.method !== 'GET') return;
    
    // Skip if request URL is from external domain (except CDNs)
    const url = new URL(event.request.url);
    if (url.origin !== location.origin && !url.hostname.includes('cdnjs') && !url.hostname.includes('fonts.googleapis.com')) {
        return;
    }
    
    event.respondWith(
        fetch(event.request)
            .then(response => {
                // Cache successful responses
                if (response.status === 200) {
                    const responseToCache = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, responseToCache);
                    });
                }
                return response;
            })
            .catch(() => {
                // Fallback to cache
                return caches.match(event.request)
                    .then(cachedResponse => {
                        if (cachedResponse) {
                            return cachedResponse;
                        }
                        // Return offline page for navigation requests
                        if (event.request.mode === 'navigate') {
                            return caches.match('/gsa/offline.html');
                        }
                        return new Response('Offline - Please check your connection', {
                            status: 503,
                            statusText: 'Service Unavailable'
                        });
                    });
            })
    );
});

// ============================================================
// PUSH NOTIFICATIONS
// ============================================================

self.addEventListener('push', event => {
    console.log('[Service Worker] Push received:', event);
    
    let data = {
        title: 'Attendance Notification',
        body: 'You have a new attendance update',
        icon: '/gsa/assets/logo-192.png',
        badge: '/gsa/assets/badge.png',
        tag: 'attendance',
        vibrate: [200, 100, 200],
        data: {
            url: '/gsa/admin/manage_attendance.php',
            timestamp: Date.now()
        }
    };
    
    // Parse push data
    if (event.data) {
        try {
            const parsedData = event.data.json();
            data = { ...data, ...parsedData };
        } catch (e) {
            // If not JSON, use as body text
            data.body = event.data.text();
        }
    }
    
    // Ensure required fields
    data.title = data.title || 'Attendance Notification';
    data.body = data.body || 'You have a new attendance update';
    data.icon = data.icon || '/gsa/assets/logo-192.png';
    data.badge = data.badge || '/gsa/assets/badge.png';
    
    // Show notification
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon,
            badge: data.badge,
            tag: data.tag || 'attendance',
            vibrate: data.vibrate || [200, 100, 200],
            data: data.data || {},
            actions: data.actions || [
                { action: 'view', title: 'View Details' },
                { action: 'dismiss', title: 'Dismiss' }
            ],
            requireInteraction: data.requireInteraction || false,
            silent: data.silent || false
        })
    );
});

// ============================================================
// NOTIFICATION CLICK HANDLER
// ============================================================

self.addEventListener('notificationclick', event => {
    console.log('[Service Worker] Notification clicked:', event);
    
    event.notification.close();
    
    const notificationData = event.notification.data || {};
    const urlToOpen = notificationData.url || '/gsa/admin/manage_attendance.php';
    
    // Mark notification as read via API
    if (notificationData.notification_id) {
        fetch('/gsa/admin/attendance_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `action=mark_read&notification_id=${notificationData.notification_id}`
        }).catch(err => console.error('Failed to mark notification read:', err));
    }
    
    // Handle action buttons
    if (event.action === 'dismiss') {
        // Just close notification, do nothing else
        return;
    }
    
    // Open or focus window/tab
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(windowClients => {
                // Check if there's already a window/tab open with the target URL
                for (let client of windowClients) {
                    if (client.url === urlToOpen && 'focus' in client) {
                        return client.focus();
                    }
                }
                // If no window/tab is open, open a new one
                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
            })
    );
});

// ============================================================
// BACKGROUND SYNC (for offline attendance recording)
// ============================================================

let pendingAttendanceQueue = [];

self.addEventListener('sync', event => {
    console.log('[Service Worker] Sync event:', event.tag);
    
    if (event.tag === 'sync-attendance') {
        event.waitUntil(syncPendingAttendance());
    }
});

async function syncPendingAttendance() {
    // Get pending attendance from IndexedDB (if available)
    try {
        const db = await openAttendanceDB();
        const pending = await getPendingAttendance(db);
        
        for (const record of pending) {
            try {
                const response = await fetch('/gsa/staff/attendance_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(record)
                });
                
                if (response.ok) {
                    await removePendingAttendance(db, record.id);
                    console.log('[Service Worker] Synced attendance record:', record.id);
                }
            } catch (err) {
                console.error('[Service Worker] Failed to sync record:', err);
            }
        }
        
        db.close();
    } catch (err) {
        console.error('[Service Worker] Sync failed:', err);
    }
}

// IndexedDB helpers for offline attendance
function openAttendanceDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('GSAAttendanceDB', 1);
        
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
        
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('pendingAttendance')) {
                const store = db.createObjectStore('pendingAttendance', { keyPath: 'id', autoIncrement: true });
                store.createIndex('timestamp', 'timestamp', { unique: false });
            }
        };
    });
}

function getPendingAttendance(db) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(['pendingAttendance'], 'readonly');
        const store = transaction.objectStore('pendingAttendance');
        const request = store.getAll();
        
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
    });
}

function removePendingAttendance(db, id) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(['pendingAttendance'], 'readwrite');
        const store = transaction.objectStore('pendingAttendance');
        const request = store.delete(id);
        
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve();
    });
}

// Export function for client to add to queue
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'QUEUE_ATTENDANCE') {
        console.log('[Service Worker] Queueing attendance record');
        
        openAttendanceDB().then(db => {
            const transaction = db.transaction(['pendingAttendance'], 'readwrite');
            const store = transaction.objectStore('pendingAttendance');
            store.add({
                ...event.data.record,
                timestamp: Date.now(),
                synced: false
            });
            db.close();
        }).catch(err => console.error('Failed to queue attendance:', err));
        
        // Request background sync
        event.waitUntil(
            self.registration.sync.register('sync-attendance')
                .catch(err => console.error('Background sync not supported:', err))
        );
    }
});

// ============================================================
// SUBSCRIPTION MANAGEMENT (for client to call)
// ============================================================

self.addEventListener('message', event => {
    if (event.data && event.data.type === 'GET_SUBSCRIPTION') {
        // Send current subscription status back to client
        self.registration.pushManager.getSubscription()
            .then(subscription => {
                if (event.source) {
                    event.source.postMessage({
                        type: 'SUBSCRIPTION_STATUS',
                        subscribed: !!subscription,
                        subscription: subscription
                    });
                }
            })
            .catch(err => console.error('Failed to get subscription:', err));
    }
});

// ============================================================
// PERIODIC BACKGROUND SYNC (if supported)
// ============================================================

if ('periodicSync' in self.registration) {
    self.addEventListener('periodicsync', event => {
        if (event.tag === 'daily-attendance-summary') {
            event.waitUntil(generateDailySummary());
        }
    });
}

async function generateDailySummary() {
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    const dateStr = yesterday.toISOString().split('T')[0];
    
    try {
        const response = await fetch(`/gsa/admin/attendance_api.php?action=get_dashboard_stats`);
        const data = await response.json();
        
        if (data.success && data.stats) {
            // Show notification with daily summary
            self.registration.showNotification('Daily Attendance Summary', {
                body: `Yesterday: ${data.stats.present_today} present, ${data.stats.absent_today} absent, ${data.stats.late_today} late`,
                icon: '/gsa/assets/logo-192.png',
                badge: '/gsa/assets/badge.png',
                tag: 'daily-summary',
                data: { url: '/gsa/admin/manage_attendance.php' }
            });
        }
    } catch (err) {
        console.error('Failed to generate daily summary:', err);
    }
}

// ============================================================
// UTILITY: Check if user is admin
// ============================================================

async function isAdminUser() {
    try {
        const response = await fetch('/gsa/admin/attendance_api.php?action=get_unread_count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        return response.ok;
    } catch {
        return false;
    }
}

// ============================================================
// LOGGING
// ============================================================

console.log('[Service Worker] Service Worker loaded and ready');