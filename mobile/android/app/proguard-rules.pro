-keep class com.fineoutput.wabusiness.** { *; }
-keep class com.dexterous.** { *; }
-keepattributes *Annotation*
-dontwarn com.google.android.play.core.**

# shared_preferences_android: R8 strips SharedPreferencesApiRegistrar suspend methods -> NoSuchMethodError in release
-keep class io.flutter.plugins.sharedpreferences.** { *; }
-keep class io.flutter.plugins.** { *; }
-keep class kotlin.coroutines.** { *; }
-keep class kotlinx.coroutines.** { *; }
-dontwarn kotlinx.coroutines.**
