<?php

namespace App\Http\Controllers;

use App\Services\Discount\DiscountService;
use App\Services\Discount\FixedDiscount;
use App\Services\Discount\PercentageDiscount;
use App\Services\Discount\LoyaltyDiscount;
use Illuminate\Http\Request;

//last question
class PriceCountController extends Controller
{
    //calculate based on service
    public function calculate(Request $request){
       $type = $request->query('type');
       $originalPrice = (float) $request->query('original');
        $value = $request->query('value');

        switch ($type) {
            case 'fixed':
                $strategy = new FixedDiscount((float) $value);
                break;

            case 'percentage':
                $strategy = new PercentageDiscount((float) $value);
                break;

            case 'loyalty':
                $strategy = new LoyaltyDiscount();
                break;

            default:
                return response()->json([
                    'message' => 'Invalid discount type.'
                ],400);
        }
         $discountService = new DiscountService($strategy);

        $discountedPrice = $discountService->applyDiscount($originalPrice);

    return response()->json([
            'original' => $originalPrice,
            'discounted_amount' => $discountedPrice
        ],200);
        }
    }

