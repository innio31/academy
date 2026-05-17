<?php
// /central_bank/id_cards/templates/card_style.php - CSS for ID cards

function getCardStyles()
{
    return '
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        
        .id-card-container {
            display: flex;
            flex-direction: column;
            gap: 4mm;
            padding: 10mm;
            background: #e0e0e0;
        }
        
        .id-card {
            page-break-after: always;
            break-inside: avoid;
        }
        
        .card-side {
            margin-bottom: 4mm;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .id-card-container {
                padding: 0;
                background: white;
            }
            .id-card {
                page-break-after: always;
                break-inside: avoid;
            }
            .no-print {
                display: none;
            }
        }
        
        @page {
            size: 85.6mm 54mm;
            margin: 0mm;
        }
    </style>
    ';
}
