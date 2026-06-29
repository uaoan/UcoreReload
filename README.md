# UcoreReload fixed: dependency/AAR/resource/native-so hot patch

This version fixes cases where the installed host APK does not contain SDK/AAR/third-party dependencies newly added in the hot-update APK.

Key changes:

1. Patch APK is loaded with an independent child-first DexClassLoader, so newly added implementation dependencies inside the patch APK are loaded from the patch.
2. Patch APK native libraries under `lib/<abi>/*.so` are extracted and added to the patch class loader search path.
3. Native library handling is stricter and more debuggable:
   - detects current process ABI (64-bit process only uses 64-bit ABI dirs; 32-bit process only uses 32-bit ABI dirs),
   - copies compatible `.so` files into both ABI subdirectories and a flat compatibility directory,
   - includes host native library dirs as fallback,
   - overrides `ChildFirstDexClassLoader.findLibrary()` to locate copied `.so` files explicitly,
   - logs `apkSo`, `copied`, `compatibleAbis`, and final `native library searchPath` in `dumpDebugInfo()` logs.
4. Activity resources/theme handling order is fixed: Activity baseContext/resources/classLoader are switched to HotPatchContext first, cached theme/inflater are cleared, then patch Manifest/Application/Activity theme is applied.
5. This fixes AppCompatActivity crashes such as: `You need to use a Theme.AppCompat theme (or descendant) with this activity` when AppCompat/custom SDK/AAR resources only exist in the patch APK.

Important:

- Dependencies newly added by the patch must use `implementation` in the patch project, not `compileOnly`, otherwise they will not be packaged into the patch APK.
- For IJK/GSYPlayer, the patch APK must contain the correct ABI native libraries, such as `libijksdl.so`, `libijkplayer.so`, and `libijkffmpeg.so` under `lib/arm64-v8a/` on a 64-bit process. A 64-bit Android process cannot load only `armeabi-v7a` `.so` files.
