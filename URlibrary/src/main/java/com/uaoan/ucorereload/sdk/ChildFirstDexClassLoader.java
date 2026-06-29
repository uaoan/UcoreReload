package com.uaoan.ucorereload.sdk;

import dalvik.system.DexClassLoader;

import java.io.File;

/**
 * 补丁 ClassLoader。
 *
 * 关键点：补丁里新增的三方库/AAR 也必须优先从补丁 APK 自己的 dex 中解析，
 * 否则宿主没有该库会 ClassNotFound，宿主有旧版本又容易 NoSuchMethodError。
 *
 * 只把 Android/Java 系统类、UcoreReload SDK 类、宿主 Application/BuildConfig 走 parent-first，
 * 其它类默认 child-first。
 */
final class ChildFirstDexClassLoader extends DexClassLoader {
    private final String[] parentFirstPrefixes;
    private final String[] librarySearchPaths;

    ChildFirstDexClassLoader(String dexPath,
                             String optimizedDirectory,
                             String librarySearchPath,
                             ClassLoader parent,
                             String childFirstPrefix,
                             String[] parentFirstPrefixes) {
        super(dexPath, optimizedDirectory, librarySearchPath, parent);
        this.parentFirstPrefixes = mergeParentFirst(parentFirstPrefixes);
        this.librarySearchPaths = splitLibrarySearchPath(librarySearchPath);
    }

    @Override
    protected Class<?> loadClass(String name, boolean resolve) throws ClassNotFoundException {
        if (shouldParentFirst(name)) {
            return super.loadClass(name, resolve);
        }

        Class<?> clazz = findLoadedClass(name);
        if (clazz == null) {
            try {
                clazz = findClass(name);
            } catch (ClassNotFoundException ignored) {
                clazz = null;
            }
        }
        if (clazz == null) {
            clazz = super.loadClass(name, false);
        }
        if (resolve) {
            resolveClass(clazz);
        }
        return clazz;
    }


    @Override
    public String findLibrary(String name) {
        String mappedName = mapLibraryNameCompat(name);
        if (librarySearchPaths != null && mappedName != null) {
            for (String path : librarySearchPaths) {
                if (path == null || path.length() == 0) {
                    continue;
                }
                File lib = new File(path, mappedName);
                if (lib.exists() && lib.isFile()) {
                    return lib.getAbsolutePath();
                }
            }
        }
        return super.findLibrary(name);
    }

    private static String mapLibraryNameCompat(String name) {
        if (name == null || name.length() == 0) {
            return name;
        }
        if (name.startsWith("lib") && name.endsWith(".so")) {
            return name;
        }
        return System.mapLibraryName(name);
    }

    private static String[] splitLibrarySearchPath(String librarySearchPath) {
        if (librarySearchPath == null || librarySearchPath.length() == 0) {
            return new String[0];
        }
        return librarySearchPath.split(File.pathSeparator);
    }

    private boolean shouldParentFirst(String name) {
        if (name == null || name.length() == 0) {
            return true;
        }
        for (String prefix : parentFirstPrefixes) {
            if (prefix != null && prefix.length() > 0 && name.startsWith(prefix)) {
                return true;
            }
        }
        return false;
    }

    private static String[] mergeParentFirst(String[] extra) {
        String[] base = new String[]{
                "java.",
                "javax.",
                "android.",
                "dalvik.",
                "libcore.",
                "sun.",
                "com.android.",
                "com.uaoan.ucorereload.sdk."
        };
        if (extra == null || extra.length == 0) {
            return base;
        }
        String[] result = new String[base.length + extra.length];
        System.arraycopy(base, 0, result, 0, base.length);
        System.arraycopy(extra, 0, result, base.length, extra.length);
        return result;
    }
}
