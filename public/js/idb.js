let dbPromise = null;

async function waitForIdb() {
  if (typeof idb !== "undefined") {
    return idb;
  }
  if (window.idbLoadFailed) {
    throw new Error("idb library failed to load, including fallback.");
  }
  return new Promise((resolve, reject) => {
    const maxAttempts = 50; // Wait up to 5 seconds (100ms * 50)
    let attempts = 0;
    const checkIdb = () => {
      if (typeof idb !== "undefined") {
        resolve(idb);
      } else if (attempts >= maxAttempts || window.idbLoadFailed) {
        reject(
          new Error(
            "idb library is not defined after waiting. Ensure the idb script is loaded."
          )
        );
      } else {
        attempts++;
        setTimeout(checkIdb, 100);
      }
    };
    checkIdb();
  });
}

async function initializeDb() {
  if (!dbPromise) {
    const idb = await waitForIdb();
    dbPromise = idb.openDB("zeronote-offline", 1, {
      upgrade(db) {
        db.createObjectStore("offlineRequests", { keyPath: "timestamp" });
        db.createObjectStore("notes", { keyPath: "id" });
      },
    });
  }
  return dbPromise;
}

async function saveToIndexedDB(storeName, data) {
  try {
    const db = await initializeDb();
    const tx = db.transaction(storeName, "readwrite");
    await tx.store.put(data);
    await tx.done;
  } catch (error) {
    console.error(`Failed to save to IndexedDB store ${storeName}:`, error);
    throw error;
  }
}

async function getFromIndexedDB(storeName) {
  try {
    const db = await initializeDb();
    return await db.getAll(storeName);
  } catch (error) {
    console.error(`Failed to get from IndexedDB store ${storeName}:`, error);
    throw error;
  }
}

async function deleteFromIndexedDB(storeName, key) {
  try {
    const db = await initializeDb();
    const tx = db.transaction(storeName, "readwrite");
    await tx.store.delete(key);
    await tx.done;
  } catch (error) {
    console.error(`Failed to delete from IndexedDB store ${storeName}:`, error);
    throw error;
  }
}
