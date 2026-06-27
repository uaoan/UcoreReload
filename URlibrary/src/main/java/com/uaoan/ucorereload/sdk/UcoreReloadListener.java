package com.uaoan.ucorereload.sdk;

import java.io.File;

public interface UcoreReloadListener {
    void onCheckStart();

    void onPatchInfo(UpdateInfo info);

    void onNoPatch(String reason);

    void onDownloadProgress(int percent, long downloadedBytes, long totalBytes);

    void onDownloaded(File patchFile);

    void onPatchLoaded(UpdateInfo info, PatchHandle handle);

    void onNeedRestart(UpdateInfo info);

    void onError(Throwable throwable);
}
