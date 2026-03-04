'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('kioskAPI', {
    openUrl: (url, id) => ipcRenderer.invoke('open-url', url, id),
    goHome: () => ipcRenderer.invoke('go-home'),
    notifyActivity: () => ipcRenderer.send('user-activity'),
    fetchApi: (params) => ipcRenderer.invoke('fetch-api', params),
    on: (event, callback) => {
        const allowed = ['browserview-opened', 'browserview-closed', 'idle-timeout', 'url-blocked'];
        if (!allowed.includes(event)) return;
        const fn = (_e, ...args) => callback(...args);
        ipcRenderer.on(event, fn);
        return () => ipcRenderer.removeListener(event, fn);
    },
});
