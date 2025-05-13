const CACHE_NAME = "zeronote-cache-v1";
const urlsToCache = [
  "/",
  "/notes_frontend.php",
  "/style.css",
  "/js/ui.js",
  "/js/api.js",
  "/js/autosave.js",
  "/js/notes.js",
  "/js/idb.min.js",
  "/js/idb.js",
  "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css",
  "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js",
  "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log("Caching assets");
      return cache.addAll(urlsToCache);
    })
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    })
  );
});

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (
    url.pathname === "/notes_backend.php" &&
    event.request.method === "POST"
  ) {
    event.respondWith(
      (async () => {
        // Clone the request immediately for fetch
        const fetchRequest = event.request.clone();
        try {
          const response = await fetch(fetchRequest);
          return response;
        } catch (error) {
          console.error("Fetch failed, queuing offline request:", error);
          try {
            // Pass the original request to queueOfflineRequest
            return await queueOfflineRequest(event.request);
          } catch (queueError) {
            console.error("Failed to queue offline request:", queueError);
            return new Response(
              JSON.stringify({
                success: false,
                offline: true,
                error: queueError.message,
              }),
              { headers: { "Content-Type": "application/json" } }
            );
          }
        }
      })()
    );
  } else {
    event.respondWith(
      caches.match(event.request).then((cachedResponse) => {
        if (cachedResponse) return cachedResponse;
        return fetch(event.request)
          .then((networkResponse) => {
            if (
              networkResponse.ok &&
              !url.pathname.startsWith("/notes_backend.php")
            ) {
              caches.open(CACHE_NAME).then((cache) => {
                cache.put(event.request, networkResponse.clone());
              });
            }
            return networkResponse;
          })
          .catch(() => {
            return caches.match("/notes_frontend.php");
          });
      })
    );
  }
});

async function queueOfflineRequest(request) {
  // Clone the request again for JSON parsing
  const requestClone = request.clone();
  let requestData;
  try {
    requestData = await requestClone.json();
  } catch (error) {
    console.error("Failed to parse request JSON:", error);
    throw new Error("Invalid request body");
  }

  try {
    await saveToIndexedDB("offlineRequests", {
      url: request.url,
      method: request.method,
      body: requestData,
      timestamp: Date.now(),
    });
    return new Response(JSON.stringify({ success: false, offline: true }), {
      headers: { "Content-Type": "application/json" },
    });
  } catch (error) {
    console.error("Failed to save offline request to IndexedDB:", error);
    throw new Error("Failed to queue request");
  }
}
