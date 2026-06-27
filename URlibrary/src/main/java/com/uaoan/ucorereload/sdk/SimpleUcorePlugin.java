package com.uaoan.ucorereload.sdk;

import android.content.Intent;
import android.os.Bundle;

/**
 * 插件页面默认空实现，业务热更新入口继承它即可。
 */
public abstract class SimpleUcorePlugin implements UcorePlugin {
    @Override public void onStart() { }
    @Override public void onResume() { }
    @Override public void onPause() { }
    @Override public void onStop() { }
    @Override public void onDestroy() { }
    @Override public void onActivityResult(int requestCode, int resultCode, Intent data) { }
    @Override public void onSaveInstanceState(Bundle outState) { }
    @Override public boolean onBackPressed() { return false; }
}
