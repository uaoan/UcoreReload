package com.uaoan.ucorereload.sdk;

import android.os.Bundle;
import android.view.Gravity;
import android.widget.TextView;

import androidx.annotation.Nullable;

/**
 * 已注册在宿主 Manifest 的空壳 Activity。
 * 真正显示时会在 UcoreReloadInstrumentation.newActivity 中被替换成补丁 APK 里的目标 Activity。
 */
public class UcoreStubActivity extends UcoreActivity {
    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        TextView view = new TextView(this);
        int padding = (int) (24 * getResources().getDisplayMetrics().density + 0.5f);
        view.setPadding(padding, padding, padding, padding);
        view.setGravity(Gravity.CENTER);
        view.setText("UcoreReload StubActivity\n如果看到这个页面，说明目标 Activity 没有被热更新代理创建成功。");
        setContentView(view);
    }
}
