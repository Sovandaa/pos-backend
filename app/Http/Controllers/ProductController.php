<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class ProductController extends Controller
{
	public function index()
	{
		return response()->json(Product::orderBy('id', 'desc')->get());
	}

	public function store(Request $request)
	{
		$data = $request->validate([
			'name' => ['required', 'string', 'max:255'],
			'description' => ['nullable', 'string'],
			'price' => ['required', 'numeric', 'min:0'],
			'stock' => ['required', 'integer', 'min:0'],
		]);

		$product = Product::create($data);
		return response()->json($product, 201);
	}

	public function show(int $id)
	{
		$product = Product::findOrFail($id);
		return response()->json($product);
	}

	public function update(Request $request, int $id)
	{
		$product = Product::findOrFail($id);

		$data = $request->validate([
			'name' => ['sometimes', 'string', 'max:255'],
			'description' => ['sometimes', 'nullable', 'string'],
			'price' => ['sometimes', 'numeric', 'min:0'],
			'stock' => ['sometimes', 'integer', 'min:0'],
		]);

		$product->update($data);
		return response()->json($product);
	}

	public function destroy(int $id)
	{
		$product = Product::findOrFail($id);
		$product->delete();
		return response()->json(null, 204);
	}
}

