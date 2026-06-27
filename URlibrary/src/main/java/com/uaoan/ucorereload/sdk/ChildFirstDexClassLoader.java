package com.uaoan.ucorereload.sdk;

import dalvik.system.DexClassLoader;

/**
 * 只让宿主业务包名走 child-first，避免补丁 APK 里重复打包的 sdk 类覆盖宿主 sdk 单例。
 */
final class ChildFirstDexClassLoader extends DexClassLoader {
    private final String childFirstPrefix;
    private final String[] parentFirstPrefixes;

    ChildFirstDexClassLoader(String dexPath,
                             String optimizedDirectory,
                             String librarySearchPath,
                             ClassLoader parent,
                             String childFirstPrefix,
                             String[] parentFirstPrefixes) {
        super(dexPath, optimizedDirectory, librarySearchPath, parent);
        this.childFirstPrefix = childFirstPrefix == null ? "" : childFirstPrefix;
        this.parentFirstPrefixes = parentFirstPrefixes == null ? new String[0] : parentFirstPrefixes;
    }

    @Override
    protected Class<?> loadClass(String name, boolean resolve) throws ClassNotFoundException {
        if (shouldChildFirst(name)) {
            Class<?> clazz = findLoadedClass(name);
            if (clazz == null) {
                try {
                    clazz = findClass(name);
                } catch (ClassNotFoundException ignored) {
                    clazz = null;
                }
            }
            if (clazz != null) {
                if (resolve) {
                    resolveClass(clazz);
                }
                return clazz;
            }
        }
        return super.loadClass(name, resolve);
    }

    private boolean shouldChildFirst(String name) {
        if (name == null || childFirstPrefix.length() == 0 || !name.startsWith(childFirstPrefix)) {
            return false;
        }
        for (String prefix : parentFirstPrefixes) {
            if (prefix != null && prefix.length() > 0 && name.startsWith(prefix)) {
                return false;
            }
        }
        return true;
    }
}
