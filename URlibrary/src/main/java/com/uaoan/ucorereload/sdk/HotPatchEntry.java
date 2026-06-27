package com.uaoan.ucorereload.sdk;

import android.content.Context;

public interface HotPatchEntry {
    void onLoad(Context patchContext, PatchHandle handle) throws Exception;
}
