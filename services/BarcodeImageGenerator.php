<?php

class BarcodeImageGenerator {
    
    /**
     * Generate barcode image using a proper EAN-13 SVG approach
     */
    public function generateBarcodeSVG($barcode, $width = 250, $height = 100) {
        if (strlen($barcode) !== 13) {
            throw new Exception('Invalid barcode length for EAN-13');
        }
        
        $patterns = $this->getEAN13Patterns();
        $leftPatterns = $this->getLeftPatterns();
        $rightPatterns = $this->getRightPatterns();
        
        // Calculate dimensions
        $totalModules = 95; // Standard EAN-13 has 95 modules
        $moduleWidth = ($width - 20) / $totalModules; // Leave 10px margin on each side
        $barcodeHeight = $height - 25; // Leave space for text
        $textHeight = 15;
        $startX = 10; // Left margin
        
        $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="100%" height="100%" fill="white" stroke="#ddd" stroke-width="1"/>';
        
        $x = $startX;
        
        // Start guard (101)
        $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, true);
        $x += $moduleWidth;
        $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, false);
        $x += $moduleWidth;
        $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, true);
        $x += $moduleWidth;
        
        // Left group (6 digits)
        $firstDigit = (int)$barcode[0];
        $leftGroup = substr($barcode, 1, 6);
        
        for ($i = 0; $i < 6; $i++) {
            $digit = (int)$leftGroup[$i];
            $useA = ($patterns[$firstDigit][$i] === 'A');
            $pattern = $useA ? $leftPatterns['A'][$digit] : $leftPatterns['B'][$digit];
            
            for ($j = 0; $j < 7; $j++) {
                $isBlack = ($pattern[$j] === '1');
                $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, $isBlack);
                $x += $moduleWidth;
            }
        }
        
        // Center guard (01010)
        $centerGuard = '01010';
        for ($i = 0; $i < 5; $i++) {
            $isBlack = ($centerGuard[$i] === '1');
            $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, $isBlack);
            $x += $moduleWidth;
        }
        
        // Right group (6 digits)
        $rightGroup = substr($barcode, 7, 6);
        
        for ($i = 0; $i < 6; $i++) {
            $digit = (int)$rightGroup[$i];
            $pattern = $rightPatterns[$digit];
            
            for ($j = 0; $j < 7; $j++) {
                $isBlack = ($pattern[$j] === '1');
                $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, $isBlack);
                $x += $moduleWidth;
            }
        }
        
        // End guard (101)
        $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, true);
        $x += $moduleWidth;
        $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, false);
        $x += $moduleWidth;
        $svg .= $this->drawBar($x, 5, $moduleWidth, $barcodeHeight, true);
        
        // Add barcode text with proper EAN-13 formatting
        $textY = $height - 5;
        $fontSize = 12;
        
        // Format the barcode text like: 7 801983 601838
        $formattedText = $barcode[0] . ' ' . substr($barcode, 1, 6) . ' ' . substr($barcode, 7, 6);
        
        // Center the entire text under the barcode
        $centerX = $width / 2;
        $svg .= '<text x="' . $centerX . '" y="' . $textY . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="' . $fontSize . '" font-weight="normal" fill="black">' . $formattedText . '</text>';
        
        $svg .= '</svg>';
        
        return $svg;
    }
    
    /**
     * Generate barcode image as base64 data URL
     */
    public function generateBarcodeDataURL($barcode, $width = 250, $height = 100) {
        $svg = $this->generateBarcodeSVG($barcode, $width, $height);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
    
    /**
     * Save barcode as SVG file
     */
    public function saveBarcodeImage($barcode, $filepath, $width = 200, $height = 80) {
        $svg = $this->generateBarcodeSVG($barcode, $width, $height);
        return file_put_contents($filepath, $svg);
    }
    
    private function drawBar($x, $y, $width, $height, $black) {
        $color = $black ? 'black' : 'white';
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $width . '" height="' . $height . '" fill="' . $color . '"/>';
    }
    
    private function getEAN13Patterns() {
        return [
            0 => ['A', 'A', 'A', 'A', 'A', 'A'],
            1 => ['A', 'A', 'B', 'A', 'B', 'B'],
            2 => ['A', 'A', 'B', 'B', 'A', 'B'],
            3 => ['A', 'A', 'B', 'B', 'B', 'A'],
            4 => ['A', 'B', 'A', 'A', 'B', 'B'],
            5 => ['A', 'B', 'B', 'A', 'A', 'B'],
            6 => ['A', 'B', 'B', 'B', 'A', 'A'],
            7 => ['A', 'B', 'A', 'B', 'A', 'B'],
            8 => ['A', 'B', 'A', 'B', 'B', 'A'],
            9 => ['A', 'B', 'B', 'A', 'B', 'A']
        ];
    }
    
    private function getLeftPatterns() {
        return [
            'A' => [
                0 => '0001101',
                1 => '0011001',
                2 => '0010011',
                3 => '0111101',
                4 => '0100011',
                5 => '0110001',
                6 => '0101111',
                7 => '0111011',
                8 => '0110111',
                9 => '0001011'
            ],
            'B' => [
                0 => '0100111',
                1 => '0110011',
                2 => '0011011',
                3 => '0100001',
                4 => '0011101',
                5 => '0111001',
                6 => '0000101',
                7 => '0010001',
                8 => '0001001',
                9 => '0010111'
            ]
        ];
    }
    
    private function getRightPatterns() {
        return [
            0 => '1110010',
            1 => '1100110',
            2 => '1101100',
            3 => '1000010',
            4 => '1011100',
            5 => '1001110',
            6 => '1010000',
            7 => '1000100',
            8 => '1001000',
            9 => '1110100'
        ];
    }
}
