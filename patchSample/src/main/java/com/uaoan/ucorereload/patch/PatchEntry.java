package com.uaoan.ucorereload.patch;

import android.content.Context;
import android.widget.Toast;

import com.uaoan.ucorereload.sdk.HotPatchEntry;
import com.uaoan.ucorereload.sdk.PatchHandle;

public class PatchEntry implements HotPatchEntry {
    @Override
    public void onLoad(Context patchContext, PatchHandle handle) {
        String message = handle.getString("hot_message", "补丁代码已加载");
        Toast.makeText(patchContext.getApplicationContext(), message, Toast.LENGTH_LONG).show();
    }
}
