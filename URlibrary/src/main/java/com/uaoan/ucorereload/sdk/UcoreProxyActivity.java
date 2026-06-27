package com.uaoan.ucorereload.sdk;

import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.widget.TextView;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

/**
 * 宿主 Manifest 里只注册这个代理 Activity 的子类。
 */
public class UcoreProxyActivity extends AppCompatActivity {
    private UcorePlugin plugin;

    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        try {
            plugin = UcoreReload.createPluginForActivity(this, savedInstanceState);
            if (plugin == null) {
                showFallback("未找到热更新页面入口。\n请确认 Application 中配置了 entryClass，默认是 包名.HotMain。");
            }
        } catch (Throwable throwable) {
            showFallback("加载热更新页面失败：\n" + throwable.getClass().getSimpleName() + ": " + throwable.getMessage());
        }
    }

    private void showFallback(String message) {
        TextView view = new TextView(this);
        int padding = dp(24);
        view.setPadding(padding, padding, padding, padding);
        view.setGravity(Gravity.CENTER_VERTICAL);
        view.setTextSize(17);
        view.setText(message);
        setContentView(view);
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }

    public UcorePlugin getUcorePlugin() {
        return plugin;
    }

    @Override protected void onStart() { super.onStart(); if (plugin != null) plugin.onStart(); }
    @Override protected void onResume() { super.onResume(); if (plugin != null) plugin.onResume(); }
    @Override protected void onPause() { if (plugin != null) plugin.onPause(); super.onPause(); }
    @Override protected void onStop() { if (plugin != null) plugin.onStop(); super.onStop(); }
    @Override protected void onDestroy() { if (plugin != null) plugin.onDestroy(); super.onDestroy(); }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, @Nullable Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (plugin != null) plugin.onActivityResult(requestCode, resultCode, data);
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        if (plugin != null) plugin.onSaveInstanceState(outState);
        super.onSaveInstanceState(outState);
    }

    @Override
    public void onBackPressed() {
        if (plugin != null && plugin.onBackPressed()) {
            return;
        }
        super.onBackPressed();
    }
}
