<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index()
    {
        $units = Unit::latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Data satuan berhasil diambil',
            'data' => $units,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'symbol' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        $unit = Unit::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Satuan berhasil ditambahkan',
            'data' => $unit,
        ], 201);
    }

    public function show(Unit $unit)
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail satuan berhasil diambil',
            'data' => $unit,
        ]);
    }

    public function update(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'symbol' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        $unit->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Satuan berhasil diperbarui',
            'data' => $unit,
        ]);
    }

    public function destroy(Unit $unit)
    {
        $unit->delete();

        return response()->json([
            'success' => true,
            'message' => 'Satuan berhasil dihapus',
        ]);
    }
}