package com.uaoan.ucorereload.sdk;

import java.io.File;

public class SimpleUcoreReloadListener implements UcoreReloadListener {
    @Override
    public void onCheckStart() {
    }

    @Override
    public void onPatchInfo(UpdateInfo info) {
    }

    @Override
    public void onNoPatch(String reason) {
    }

    @Override
    public void onDownloadProgress(int percent, long downloadedBytes, long totalBytes) {
    }

    @Override
    public void onDownloaded(File patchFile) {
    }

    @Override
    public void onPatchLoaded(UpdateInfo info, PatchHandle handle) {
    }

    @Override
    public void onNeedRestart(UpdateInfo info) {
    }

    @Override
    public void onError(Throwable throwable) {
    }
}
