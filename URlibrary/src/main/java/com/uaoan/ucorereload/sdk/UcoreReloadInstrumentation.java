package com.uaoan.ucorereload.sdk;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.os.IBinder;
import android.os.PersistableBundle;
import android.util.Log;

/**
 * Activity 热更新代理 Hook：
 * 1. startActivity 时，如果目标 Activity 没有注册在宿主 Manifest，先改成已注册的 UcoreStubActivity；
 * 2. 系统创建 UcoreStubActivity 时，再替换成补丁 APK 里的真实 Activity 类。
 */
final class UcoreReloadInstrumentation extends Instrumentation {
    private final Instrumentation base;
    private final Context appContext;

    UcoreReloadInstrumentation(Context appContext, Instrumentation base) {
        Context c = appContext == null ? null : appContext.getApplicationContext();
        this.appContext = c == null ? appContext : c;
        this.base = base;
    }

    @Override
    public Activity newActivity(ClassLoader cl, String className, Intent intent)
            throws InstantiationException, IllegalAccessException, ClassNotFoundException {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        String targetClass = intent == null ? null : intent.getStringExtra(UcoreReload.EXTRA_TARGET_ACTIVITY);

        if (handle != null && targetClass != null && targetClass.length() > 0) {
            try {
                if (intent != null && handle.getClassLoader() != null) {
                    intent.setExtrasClassLoader(handle.getClassLoader());
                }
                Activity activity = base.newActivity(handle.getClassLoader(), targetClass, intent);
                UcoreReload.logDebug("create proxy activity: " + className + " -> " + targetClass);
                UcoreReload.applyPatchResourcesToActivity(activity);
                return activity;
            } catch (ClassNotFoundException e) {
                throw e;
            } catch (Throwable throwable) {
                Log.w("UcoreReload", "create plugin activity failed: " + targetClass, throwable);
                throw new ClassNotFoundException("创建热更新 Activity 失败：" + targetClass, throwable);
            }
        }

        if (handle != null && UcoreReload.shouldLoadActivityFromPatch(appContext, className)) {
            try {
                Activity activity = base.newActivity(handle.getClassLoader(), className, intent);
                UcoreReload.applyPatchResourcesToActivity(activity);
                return activity;
            } catch (ClassNotFoundException e) {
                // 补丁里没有这个 Activity 时走宿主。
            } catch (Throwable ignored) {
                // 补丁 Activity 创建失败时走宿主，避免启动崩溃。
            }
        }
        Activity activity = base.newActivity(cl, className, intent);
        UcoreReload.applyPatchResourcesToActivity(activity);
        return activity;
    }

    @SuppressWarnings("unused")
    public Instrumentation.ActivityResult execStartActivity(Context who, IBinder contextThread, IBinder token,
                                            Activity target, Intent intent, int requestCode, Bundle options) {
        try {
            Intent fixed = UcoreReload.wrapActivityIntentIfNeeded(who == null ? appContext : who, intent);
            UcoreReload.logDebug("execStartActivity(Activity) intent=" + String.valueOf(intent) + " fixed=" + String.valueOf(fixed));
            return invokeExecStartActivity(who, contextThread, token, target, fixed, requestCode, options);
        } catch (Throwable throwable) {
            throw new RuntimeException(throwable);
        }
    }

    @SuppressWarnings("unused")
    public Instrumentation.ActivityResult execStartActivity(Context who, IBinder contextThread, IBinder token,
                                            String target, Intent intent, int requestCode, Bundle options) {
        try {
            Intent fixed = UcoreReload.wrapActivityIntentIfNeeded(who == null ? appContext : who, intent);
            UcoreReload.logDebug("execStartActivity(String) intent=" + String.valueOf(intent) + " fixed=" + String.valueOf(fixed));
            return invokeExecStartActivity(who, contextThread, token, target, fixed, requestCode, options);
        } catch (Throwable throwable) {
            throw new RuntimeException(throwable);
        }
    }

    @SuppressWarnings("unused")
    public Instrumentation.ActivityResult execStartActivity(Context who, IBinder contextThread, IBinder token,
                                            android.app.Fragment target, Intent intent, int requestCode, Bundle options) {
        try {
            Intent fixed = UcoreReload.wrapActivityIntentIfNeeded(who == null ? appContext : who, intent);
            java.lang.reflect.Method method = Instrumentation.class.getDeclaredMethod(
                    "execStartActivity",
                    Context.class, IBinder.class, IBinder.class, android.app.Fragment.class, Intent.class, int.class, Bundle.class);
            method.setAccessible(true);
            return (Instrumentation.ActivityResult) method.invoke(base, who, contextThread, token, target, fixed, requestCode, options);
        } catch (Throwable throwable) {
            throw new RuntimeException(throwable);
        }
    }

    @SuppressWarnings("unused")
    public void execStartActivities(Context who, IBinder contextThread, IBinder token,
                                    Activity target, Intent[] intents, Bundle options) {
        try {
            if (intents != null) {
                for (int i = 0; i < intents.length; i++) {
                    intents[i] = UcoreReload.wrapActivityIntentIfNeeded(who == null ? appContext : who, intents[i]);
                }
            }
            java.lang.reflect.Method method = Instrumentation.class.getDeclaredMethod(
                    "execStartActivities",
                    Context.class, IBinder.class, IBinder.class, Activity.class, Intent[].class, Bundle.class);
            method.setAccessible(true);
            method.invoke(base, who, contextThread, token, target, intents, options);
        } catch (Throwable throwable) {
            throw new RuntimeException(throwable);
        }
    }

    private Instrumentation.ActivityResult invokeExecStartActivity(Context who, IBinder contextThread, IBinder token,
                                                   Object target, Intent intent, int requestCode, Bundle options) throws Exception {
        java.lang.reflect.Method method;
        if (target instanceof Activity || target == null) {
            method = Instrumentation.class.getDeclaredMethod(
                    "execStartActivity",
                    Context.class, IBinder.class, IBinder.class, Activity.class, Intent.class, int.class, Bundle.class);
        } else {
            method = Instrumentation.class.getDeclaredMethod(
                    "execStartActivity",
                    Context.class, IBinder.class, IBinder.class, String.class, Intent.class, int.class, Bundle.class);
        }
        method.setAccessible(true);
        return (Instrumentation.ActivityResult) method.invoke(base, who, contextThread, token, target, intent, requestCode, options);
    }

    @Override
    public void callActivityOnCreate(Activity activity, Bundle icicle) {
        UcoreReload.applyPatchResourcesToActivity(activity);
        base.callActivityOnCreate(activity, icicle);
    }

    @Override
    public void callActivityOnCreate(Activity activity, Bundle icicle, PersistableBundle persistentState) {
        UcoreReload.applyPatchResourcesToActivity(activity);
        base.callActivityOnCreate(activity, icicle, persistentState);
    }

    @Override public void callActivityOnResume(Activity activity) { UcoreReload.applyPatchResourcesToActivity(activity); base.callActivityOnResume(activity); }
    @Override public void callActivityOnPause(Activity activity) { base.callActivityOnPause(activity); }
    @Override public void callActivityOnStop(Activity activity) { base.callActivityOnStop(activity); }
    @Override public void callActivityOnDestroy(Activity activity) { base.callActivityOnDestroy(activity); }
}
