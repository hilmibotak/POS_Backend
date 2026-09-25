<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Menampilkan pengaturan toko.
     */
    public function show()
    {
        $setting = Setting::first();

        if (!$setting) {
            $setting = Setting::create([
                'store_name' => 'BuildPOS',
                'address' => null,
                'phone' => null,
                'receipt_footer' => 'Terima kasih telah berbelanja.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan berhasil diambil',
            'data' => $setting,
        ]);
    }

    /**
     * Mengubah pengaturan toko.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'store_name' => [
                'required',
                'string',
                'max:255',
            ],

            'address' => [
                'nullable',
                'string',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'receipt_footer' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $setting = Setting::first();

        if (!$setting) {
            $setting = Setting::create($validated);
        } else {
            $setting->update($validated);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan berhasil disimpan',
            'data' => $setting->fresh(),
        ]);
    }
}