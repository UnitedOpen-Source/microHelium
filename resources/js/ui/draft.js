// Drafts stay in memory; never persist private source code to browser storage.
export function protectDraft(isDirty) {
    const unload = event => {
        if (!isDirty()) return;
        event.preventDefault();
        event.returnValue = '';
    };
    window.addEventListener('beforeunload', unload);
    return () => window.removeEventListener('beforeunload', unload);
}
