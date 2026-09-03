$ErrorActionPreference = 'Stop'

$presentationDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = (Resolve-Path (Join-Path $presentationDirectory '..\..')).Path
$imageDirectory = Join-Path $presentationDirectory 'images'
$slidePreviewDirectory = Join-Path $presentationDirectory 'slide-previews'
$powerPointPath = Join-Path $presentationDirectory 'inventory-forecasting-management-presentation.pptx'
$pdfPath = Join-Path $presentationDirectory 'inventory-forecasting-management-presentation.pdf'

New-Item -ItemType Directory -Path $imageDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $slidePreviewDirectory -Force | Out-Null

function ConvertTo-OfficeRgb {
    param([Parameter(Mandatory)][string] $Hex)

    $value = $Hex.TrimStart('#')
    $red = [Convert]::ToInt32($value.Substring(0, 2), 16)
    $green = [Convert]::ToInt32($value.Substring(2, 2), 16)
    $blue = [Convert]::ToInt32($value.Substring(4, 2), 16)

    return $red + ($green * 256) + ($blue * 65536)
}

$colors = @{
    Navy = ConvertTo-OfficeRgb '#0B1536'
    Ink = ConvertTo-OfficeRgb '#182230'
    Muted = ConvertTo-OfficeRgb '#667085'
    LightMuted = ConvertTo-OfficeRgb '#98A2B3'
    Border = ConvertTo-OfficeRgb '#D8E1EE'
    Canvas = ConvertTo-OfficeRgb '#F4F7FB'
    White = ConvertTo-OfficeRgb '#FFFFFF'
    Purple = ConvertTo-OfficeRgb '#6D3EF2'
    PurpleSoft = ConvertTo-OfficeRgb '#EEE9FF'
    Blue = ConvertTo-OfficeRgb '#1769E0'
    BlueSoft = ConvertTo-OfficeRgb '#E8F1FF'
    Teal = ConvertTo-OfficeRgb '#078A99'
    TealSoft = ConvertTo-OfficeRgb '#E4F7F8'
    Green = ConvertTo-OfficeRgb '#0E9F6E'
    GreenSoft = ConvertTo-OfficeRgb '#E1F7EF'
    Amber = ConvertTo-OfficeRgb '#E58A00'
    AmberSoft = ConvertTo-OfficeRgb '#FFF2D9'
    Red = ConvertTo-OfficeRgb '#E5484D'
    RedSoft = ConvertTo-OfficeRgb '#FDE7E7'
    Slate = ConvertTo-OfficeRgb '#344054'
}

$fontName = 'Segoe UI'
$slideWidth = 960.0
$slideHeight = 540.0

function Add-Text {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][string] $Text,
        [Parameter(Mandatory)][double] $Left,
        [Parameter(Mandatory)][double] $Top,
        [Parameter(Mandatory)][double] $Width,
        [Parameter(Mandatory)][double] $Height,
        [double] $Size = 18,
        [int] $Color = $colors.Ink,
        [bool] $Bold = $false,
        [int] $Align = 1,
        [int] $VerticalAlign = 3,
        [string] $Font = $fontName
    )

    $shape = $Slide.Shapes.AddTextbox(1, $Left, $Top, $Width, $Height)
    $shape.TextFrame.AutoSize = 0
    $shape.TextFrame.WordWrap = -1
    $shape.TextFrame.MarginLeft = 4
    $shape.TextFrame.MarginRight = 4
    $shape.TextFrame.MarginTop = 2
    $shape.TextFrame.MarginBottom = 2
    $shape.TextFrame.VerticalAnchor = $VerticalAlign
    $shape.TextFrame.TextRange.Text = $Text
    $shape.TextFrame.TextRange.Font.Name = $Font
    $shape.TextFrame.TextRange.Font.Size = $Size
    $shape.TextFrame.TextRange.Font.Bold = $(if ($Bold) { -1 } else { 0 })
    $shape.TextFrame.TextRange.Font.Color.RGB = $Color
    $shape.TextFrame.TextRange.ParagraphFormat.Alignment = $Align

    return $shape
}

function Add-Box {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][double] $Left,
        [Parameter(Mandatory)][double] $Top,
        [Parameter(Mandatory)][double] $Width,
        [Parameter(Mandatory)][double] $Height,
        [int] $Fill = $colors.White,
        [int] $Line = $colors.Border,
        [double] $Radius = 0,
        [double] $Transparency = 0,
        [bool] $Shadow = $false
    )

    $shapeType = $(if ($Radius -gt 0) { 5 } else { 1 })
    $shape = $Slide.Shapes.AddShape($shapeType, $Left, $Top, $Width, $Height)
    $shape.Fill.Solid()
    $shape.Fill.ForeColor.RGB = $Fill
    $shape.Fill.Transparency = $Transparency
    $shape.Line.ForeColor.RGB = $Line
    $shape.Line.Weight = 1
    if ($Shadow) {
        try {
            $shape.Shadow.Visible = -1
            $shape.Shadow.ForeColor.RGB = $colors.Navy
            $shape.Shadow.Transparency = 0.82
            $shape.Shadow.OffsetX = 0
            $shape.Shadow.OffsetY = 3
            $shape.Shadow.Blur = 6
        } catch {
            # Shadow support varies by Office build; the card still renders cleanly.
        }
    }

    return $shape
}

function Add-Line {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][double] $X1,
        [Parameter(Mandatory)][double] $Y1,
        [Parameter(Mandatory)][double] $X2,
        [Parameter(Mandatory)][double] $Y2,
        [int] $Color = $colors.Blue,
        [double] $Weight = 2,
        [bool] $Arrow = $true,
        [bool] $Dashed = $false
    )

    $line = $Slide.Shapes.AddLine($X1, $Y1, $X2, $Y2)
    $line.Line.ForeColor.RGB = $Color
    $line.Line.Weight = $Weight
    if ($Arrow) {
        $line.Line.EndArrowheadStyle = 3
    }
    if ($Dashed) {
        $line.Line.DashStyle = 4
    }

    return $line
}

function Add-Pill {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][string] $Text,
        [Parameter(Mandatory)][double] $Left,
        [Parameter(Mandatory)][double] $Top,
        [Parameter(Mandatory)][double] $Width,
        [Parameter(Mandatory)][double] $Height,
        [int] $Fill,
        [int] $Color,
        [double] $Size = 11
    )

    $box = Add-Box -Slide $Slide -Left $Left -Top $Top -Width $Width -Height $Height -Fill $Fill -Line $Fill -Radius 12
    $null = Add-Text -Slide $Slide -Text $Text -Left $Left -Top $Top -Width $Width -Height $Height -Size $Size -Color $Color -Bold $true -Align 2
    return $box
}

function Add-Badge {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][string] $Text,
        [Parameter(Mandatory)][double] $Left,
        [Parameter(Mandatory)][double] $Top,
        [double] $Diameter = 34,
        [int] $Fill = $colors.Purple,
        [int] $Color = $colors.White,
        [double] $Size = 12
    )

    $circle = $Slide.Shapes.AddShape(9, $Left, $Top, $Diameter, $Diameter)
    $circle.Fill.Solid()
    $circle.Fill.ForeColor.RGB = $Fill
    $circle.Line.Visible = 0
    $null = Add-Text -Slide $Slide -Text $Text -Left $Left -Top $Top -Width $Diameter -Height $Diameter -Size $Size -Color $Color -Bold $true -Align 2
    return $circle
}

function Add-SlideHeader {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][string] $Title,
        [string] $Kicker = 'INVENTORY FORECASTING',
        [string] $Subtitle = ''
    )

    $null = Add-Text -Slide $Slide -Text $Kicker -Left 48 -Top 28 -Width 700 -Height 18 -Size 9 -Color $colors.Purple -Bold $true -VerticalAlign 1
    $null = Add-Text -Slide $Slide -Text $Title -Left 44 -Top 47 -Width 850 -Height 42 -Size 25 -Color $colors.Navy -Bold $true -VerticalAlign 1
    if ($Subtitle) {
        $null = Add-Text -Slide $Slide -Text $Subtitle -Left 48 -Top 88 -Width 845 -Height 34 -Size 11.5 -Color $colors.Muted -VerticalAlign 1
    }
}

function Add-Footer {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][int] $Number,
        [bool] $Dark = $false
    )

    $color = $(if ($Dark) { $colors.LightMuted } else { $colors.Muted })
    $null = Add-Text -Slide $Slide -Text 'Inventory Forecasting | Management & Team Briefing' -Left 48 -Top 514 -Width 560 -Height 16 -Size 8 -Color $color -VerticalAlign 1
    $null = Add-Text -Slide $Slide -Text ([string]$Number) -Left 840 -Top 512 -Width 72 -Height 18 -Size 8 -Color $color -Bold $true -Align 3 -VerticalAlign 1
}

function Add-Notes {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][string] $Text
    )

    try {
        $placeholders = $Slide.NotesPage.Shapes.Placeholders
        if ($placeholders.Count -ge 2) {
            $placeholders.Item(2).TextFrame.TextRange.Text = $Text
        }
    } catch {
        # The separate presenter-notes document is always generated as a fallback.
    }
}

function New-StandardSlide {
    param(
        [Parameter(Mandatory)] $Presentation,
        [Parameter(Mandatory)][string] $Title,
        [string] $Kicker = 'INVENTORY FORECASTING',
        [string] $Subtitle = ''
    )

    $slide = $Presentation.Slides.Add($Presentation.Slides.Count + 1, 12)
    $slide.FollowMasterBackground = 0
    $slide.Background.Fill.Solid()
    $slide.Background.Fill.ForeColor.RGB = $colors.Canvas
    $null = Add-SlideHeader -Slide $slide -Title $Title -Kicker $Kicker -Subtitle $Subtitle
    return $slide
}

function Add-Picture {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][double] $Left,
        [Parameter(Mandatory)][double] $Top,
        [Parameter(Mandatory)][double] $Width,
        [Parameter(Mandatory)][double] $Height
    )

    return $Slide.Shapes.AddPicture($Path, 0, -1, $Left, $Top, $Width, $Height)
}

function Add-CardHeading {
    param(
        [Parameter(Mandatory)] $Slide,
        [Parameter(Mandatory)][string] $Code,
        [Parameter(Mandatory)][string] $Title,
        [Parameter(Mandatory)][double] $Left,
        [Parameter(Mandatory)][double] $Top,
        [Parameter(Mandatory)][double] $Width,
        [int] $Accent = $colors.Blue
    )

    $null = Add-Badge -Slide $Slide -Text $Code -Left ($Left + 14) -Top ($Top + 13) -Diameter 32 -Fill $Accent -Size 10
    $null = Add-Text -Slide $Slide -Text $Title -Left ($Left + 54) -Top ($Top + 11) -Width ($Width - 66) -Height 38 -Size 14 -Color $colors.Navy -Bold $true -VerticalAlign 3
}

$powerPoint = $null
$presentation = $null

try {
    $powerPoint = New-Object -ComObject PowerPoint.Application
    $powerPoint.Visible = -1
    $presentation = $powerPoint.Presentations.Add()
    $presentation.PageSetup.SlideWidth = $slideWidth
    $presentation.PageSetup.SlideHeight = $slideHeight

    # Slide 1 - title
    $slide = $presentation.Slides.Add(1, 12)
    $slide.FollowMasterBackground = 0
    $slide.Background.Fill.Solid()
    $slide.Background.Fill.ForeColor.RGB = $colors.Navy
    $accentOne = Add-Box -Slide $slide -Left 650 -Top -70 -Width 390 -Height 260 -Fill $colors.Purple -Line $colors.Purple -Radius 24 -Transparency 0.12
    $accentOne.Rotation = 18
    $accentTwo = Add-Box -Slide $slide -Left 720 -Top 310 -Width 310 -Height 190 -Fill $colors.Teal -Line $colors.Teal -Radius 24 -Transparency 0.2
    $accentTwo.Rotation = -12
    $null = Add-Pill -Slide $slide -Text 'MANAGEMENT & TEAM BRIEFING' -Left 58 -Top 46 -Width 238 -Height 30 -Fill $colors.Purple -Color $colors.White -Size 10
    $null = Add-Text -Slide $slide -Text 'Inventory Forecasting' -Left 54 -Top 107 -Width 610 -Height 68 -Size 38 -Color $colors.White -Bold $true -VerticalAlign 1
    $null = Add-Text -Slide $slide -Text 'From daily transactions to trusted, human-controlled inventory decisions' -Left 58 -Top 181 -Width 580 -Height 58 -Size 19 -Color $colors.White -VerticalAlign 1
    $null = Add-Line -Slide $slide -X1 60 -Y1 276 -X2 805 -Y2 276 -Color $colors.Teal -Weight 3 -Arrow $false
    $steps = @(
        @{ Code = '01'; Title = 'CAPTURE'; Text = 'Sales, receipts, returns, transfers' },
        @{ Code = '02'; Title = 'UNDERSTAND'; Text = 'Ledger, FIFO, analytics, snapshots' },
        @{ Code = '03'; Title = 'PREDICT'; Text = 'Baselines + trained neural models' },
        @{ Code = '04'; Title = 'DECIDE'; Text = 'Buy, reduce, stop, transfer, clear' }
    )
    for ($i = 0; $i -lt $steps.Count; $i++) {
        $left = 60 + ($i * 205)
        $null = Add-Badge -Slide $slide -Text $steps[$i].Code -Left $left -Top 301 -Diameter 34 -Fill $(if ($i -eq 3) { $colors.Green } else { $colors.Purple }) -Size 10
        $null = Add-Text -Slide $slide -Text $steps[$i].Title -Left ($left + 43) -Top 300 -Width 148 -Height 22 -Size 11 -Color $colors.White -Bold $true -VerticalAlign 1
        $null = Add-Text -Slide $slide -Text $steps[$i].Text -Left ($left + 43) -Top 323 -Width 150 -Height 44 -Size 9.5 -Color $colors.LightMuted -VerticalAlign 1
    }
    $null = Add-Pill -Slide $slide -Text '10 phases delivered' -Left 58 -Top 421 -Width 170 -Height 32 -Fill $colors.Green -Color $colors.White -Size 10
    $null = Add-Pill -Slide $slide -Text 'Human approval retained' -Left 240 -Top 421 -Width 198 -Height 32 -Fill $colors.Teal -Color $colors.White -Size 10
    $null = Add-Pill -Slide $slide -Text 'Production controls still required' -Left 450 -Top 421 -Width 256 -Height 32 -Fill $colors.Amber -Color $colors.Navy -Size 10
    $null = Add-Text -Slide $slide -Text '03 September 2026' -Left 60 -Top 492 -Width 240 -Height 20 -Size 9 -Color $colors.LightMuted -VerticalAlign 1
    Add-Notes -Slide $slide -Text "Open with the business outcome: this system closes the loop between transactions and inventory action. It is not only a forecasting screen. It manages catalog, warehouse stock, purchasing, sales, trusted history, analytics, forecasts, and controlled recommendations. State the governance position early: recommendations remain human decisions, and production controls are still required."

    # Slide 2 - executive summary
    $slide = New-StandardSlide -Presentation $presentation -Title 'A single operating system for stock decisions' -Kicker 'EXECUTIVE SUMMARY' -Subtitle 'One source of operational truth, translated into measurable action without removing management control.'
    $columns = @(
        @{ X = 48; Accent = $colors.Blue; Soft = $colors.BlueSoft; Code = '1'; Title = 'OBSERVE'; Body = "Multi-warehouse balances`r`nAppend-only stock ledger`r`nFIFO batches and costs`r`nPurchasing, sales and returns" },
        @{ X = 349; Accent = $colors.Purple; Soft = $colors.PurpleSoft; Code = '2'; Title = 'UNDERSTAND'; Body = "Inventory health analytics`r`nDaily stock and demand history`r`nForecasts with uncertainty`r`nSeven intelligence views" },
        @{ X = 650; Accent = $colors.Green; Soft = $colors.GreenSoft; Code = '3'; Title = 'ACT'; Body = "Purchase or reduce buying`r`nDo not reorder or clear`r`nTransfer between warehouses`r`nAccept, modify or reject" }
    )
    foreach ($column in $columns) {
        $null = Add-Box -Slide $slide -Left $column.X -Top 145 -Width 262 -Height 260 -Fill $colors.White -Line $colors.Border -Radius 16 -Shadow $true
        $null = Add-Box -Slide $slide -Left $column.X -Top 145 -Width 262 -Height 9 -Fill $column.Accent -Line $column.Accent -Radius 8
        $null = Add-Badge -Slide $slide -Text $column.Code -Left ($column.X + 20) -Top 172 -Diameter 38 -Fill $column.Accent
        $null = Add-Text -Slide $slide -Text $column.Title -Left ($column.X + 70) -Top 172 -Width 170 -Height 40 -Size 17 -Color $colors.Navy -Bold $true
        $null = Add-Text -Slide $slide -Text $column.Body -Left ($column.X + 22) -Top 232 -Width 218 -Height 138 -Size 13 -Color $colors.Slate -VerticalAlign 1
    }
    $null = Add-Box -Slide $slide -Left 48 -Top 426 -Width 864 -Height 62 -Fill $colors.AmberSoft -Line $colors.Amber -Radius 14
    $null = Add-Text -Slide $slide -Text 'Management truth:' -Left 67 -Top 438 -Width 138 -Height 28 -Size 12 -Color $colors.Amber -Bold $true
    $null = Add-Text -Slide $slide -Text 'Functionally complete, but not production-ready until access roles, registration policy, hosting, backups, mail, workers, scheduler and monitoring are controlled.' -Left 203 -Top 435 -Width 685 -Height 38 -Size 11.5 -Color $colors.Navy -Bold $true -VerticalAlign 3
    Add-Footer -Slide $slide -Number 2
    Add-Notes -Slide $slide -Text "Frame the application in three verbs: observe, understand, act. Observe means the operational record is complete. Understand means history is transformed into analytics, forecasts and intelligence. Act means the engine proposes one of five inventory responses, but a user still accepts, changes or rejects it. The amber banner is the go-live boundary, not a criticism of the delivered feature set."

    # Slide 3 - existing full system infographic
    $slide = $presentation.Slides.Add($presentation.Slides.Count + 1, 12)
    $overviewImage = Join-Path $repositoryRoot 'docs\images\01-full-system-overview.png'
    $null = Add-Picture -Slide $slide -Path $overviewImage -Left 0 -Top 0 -Width $slideWidth -Height $slideHeight
    Add-Notes -Slide $slide -Text "Walk left to right. Master data defines what can be stocked and where. Operational transactions create the movement history. Daily snapshots convert movement history into a time series. Intelligence explains demand and stock health. The decision engine recommends five action types. The green bar is essential: every recommendation remains subject to human review."

    # Slide 4 - existing operating flow infographic
    $slide = $presentation.Slides.Add($presentation.Slides.Count + 1, 12)
    $operationsImage = Join-Path $repositoryRoot 'docs\images\02-business-operating-flow.png'
    $null = Add-Picture -Slide $slide -Path $operationsImage -Left 0 -Top 0 -Width $slideWidth -Height $slideHeight
    Add-Notes -Slide $slide -Text "This is the daily operating loop. Setup is performed once and maintained. Buying, receiving, managing stock, selling and returning create real transactions. Those transactions build trusted history. Forecasting and recommendation then use that history. Accepted recommendations are deliberately executed through controlled purchase-order or stock-transfer workflows; the engine does not bypass operations."

    # Slide 5 - capability map
    $slide = New-StandardSlide -Presentation $presentation -Title 'What the application contains' -Kicker 'CAPABILITY MAP' -Subtitle 'Six connected work areas cover the full inventory lifecycle and the intelligence built on top of it.'
    $capabilities = @(
        @{ X=48; Y=135; Code='CAT'; Title='Catalog'; Color=$colors.Blue; Soft=$colors.BlueSoft; Body="Categories, brands, attributes`r`nProducts, variants and SKUs" },
        @{ X=342; Y=135; Code='INV'; Title='Inventory'; Color=$colors.Teal; Soft=$colors.TealSoft; Body="Warehouses, balances, movements`r`nAdjustments, FIFO, transfers, snapshots" },
        @{ X=636; Y=135; Code='BUY'; Title='Purchasing'; Color=$colors.Purple; Soft=$colors.PurpleSoft; Body="Suppliers and SKU terms`r`nPurchase orders and goods receipts" },
        @{ X=48; Y=304; Code='SELL'; Title='Sales'; Color=$colors.Green; Soft=$colors.GreenSoft; Body="Draft and confirmed sales orders`r`nSellable and damaged returns" },
        @{ X=342; Y=304; Code='FCST'; Title='Forecasting & decisions'; Color=$colors.Purple; Soft=$colors.PurpleSoft; Body="Runs, forecasts and accuracy`r`nRecommendations and central allocation" },
        @{ X=636; Y=304; Code='AI'; Title='Advanced intelligence'; Color=$colors.Amber; Soft=$colors.AmberSoft; Body="Supplier, lost sales, anomalies`r`nRelationships, elasticity, promotions" }
    )
    foreach ($capability in $capabilities) {
        $null = Add-Box -Slide $slide -Left $capability.X -Top $capability.Y -Width 276 -Height 137 -Fill $colors.White -Line $colors.Border -Radius 16 -Shadow $true
        $null = Add-Badge -Slide $slide -Text $capability.Code -Left ($capability.X + 18) -Top ($capability.Y + 18) -Diameter 42 -Fill $capability.Color -Size 9
        $null = Add-Text -Slide $slide -Text $capability.Title -Left ($capability.X + 72) -Top ($capability.Y + 15) -Width 185 -Height 45 -Size 15 -Color $colors.Navy -Bold $true
        $null = Add-Text -Slide $slide -Text $capability.Body -Left ($capability.X + 20) -Top ($capability.Y + 72) -Width 236 -Height 50 -Size 10.5 -Color $colors.Muted -VerticalAlign 1
    }
    $null = Add-Pill -Slide $slide -Text 'All business routes require authenticated + verified users' -Left 48 -Top 464 -Width 416 -Height 30 -Fill $colors.GreenSoft -Color $colors.Green -Size 10
    $null = Add-Pill -Slide $slide -Text 'Role-based restrictions are not yet implemented' -Left 480 -Top 464 -Width 390 -Height 30 -Fill $colors.RedSoft -Color $colors.Red -Size 10
    Add-Footer -Slide $slide -Number 5
    Add-Notes -Slide $slide -Text "Use this slide as the table of contents for the application. Catalog defines the sellable unit. Inventory is the trusted stock core. Purchasing and sales create the commercial movements. Forecasting turns history into future demand and controlled inventory actions. Advanced intelligence adds supplier, demand, product, pricing and promotion signals. Every verified user can currently reach these areas because RBAC is not yet implemented."

    # Slide 6 - recommendation UI
    $slide = New-StandardSlide -Presentation $presentation -Title 'The decision workspace users see' -Kicker 'REAL APPLICATION VIEW' -Subtitle 'Recommendations combine forecast, stock, supplier terms and risk into an auditable human decision.'
    $recommendationShot = Join-Path $repositoryRoot '.playwright-mcp\page-2026-08-16T15-29-39-615Z.png'
    $null = Add-Box -Slide $slide -Left 54 -Top 126 -Width 852 -Height 374 -Fill $colors.White -Line $colors.Border -Radius 14 -Shadow $true
    $null = Add-Picture -Slide $slide -Path $recommendationShot -Left 65 -Top 137 -Width 830 -Height 350
    $null = Add-Box -Slide $slide -Left 65 -Top 137 -Width 118 -Height 24 -Fill $colors.White -Line $colors.White
    $null = Add-Text -Slide $slide -Text 'Inventory Forecasting' -Left 72 -Top 140 -Width 110 -Height 16 -Size 7.5 -Color $colors.Navy -Bold $true
    $null = Add-Pill -Slide $slide -Text 'Seeded local data - illustrative, not production performance' -Left 588 -Top 469 -Width 295 -Height 24 -Fill $colors.Navy -Color $colors.White -Size 8
    Add-Footer -Slide $slide -Number 6
    Add-Notes -Slide $slide -Text "Point out the business fields, not the visual design: SKU and warehouse, recommendation type, current and incoming stock, 30-day forecast, proposed quantity, risk badges, status and actions. Users can filter and sort the list. Accept, modify and reject are explicit decisions. This screenshot uses seeded data, so the numbers prove the workflow, not real-world performance."

    # Slide 7 - advanced intelligence UI
    $slide = New-StandardSlide -Presentation $presentation -Title 'Intelligence remains traceable to operational data' -Kicker 'REAL APPLICATION VIEWS' -Subtitle 'Supplier scorecards and promotion impact are examples of the seven intelligence views delivered.'
    $supplierShot = Join-Path $repositoryRoot '.playwright-mcp\page-2026-08-16T15-29-50-740Z.png'
    $promotionShot = Join-Path $repositoryRoot '.playwright-mcp\page-2026-08-16T15-30-51-854Z.png'
    foreach ($panel in @(
        @{ X=48; Path=$supplierShot; Title='Supplier performance'; Caption='Actual lead time, on-time rate and fill rate from PO/receipt dates' },
        @{ X=502; Path=$promotionShot; Title='Promotion impact'; Caption='Before-vs-during demand change per promoted SKU' }
    )) {
        $null = Add-Box -Slide $slide -Left $panel.X -Top 140 -Width 410 -Height 296 -Fill $colors.White -Line $colors.Border -Radius 14 -Shadow $true
        $null = Add-Picture -Slide $slide -Path $panel.Path -Left ($panel.X + 10) -Top 150 -Width 390 -Height 220
        $null = Add-Box -Slide $slide -Left ($panel.X + 10) -Top 150 -Width 57 -Height 12 -Fill $colors.White -Line $colors.White
        $null = Add-Text -Slide $slide -Text $panel.Title -Left ($panel.X + 18) -Top 382 -Width 370 -Height 25 -Size 13 -Color $colors.Navy -Bold $true -VerticalAlign 1
        $null = Add-Text -Slide $slide -Text $panel.Caption -Left ($panel.X + 18) -Top 407 -Width 370 -Height 30 -Size 9.5 -Color $colors.Muted -VerticalAlign 1
    }
    $null = Add-Pill -Slide $slide -Text 'Evidence-led signals, not autonomous actions' -Left 326 -Top 463 -Width 308 -Height 31 -Fill $colors.AmberSoft -Color $colors.Amber -Size 10
    Add-Footer -Slide $slide -Number 7
    Add-Notes -Slide $slide -Text "These screens show the same design pattern across intelligence modules: filters, transparent calculations and row-level evidence. Supplier performance comes from real purchase-order and receipt dates. Promotion impact compares the promotion window with an equal preceding window. Similar transparency applies to lost-sales estimates, anomalies, similarity, cannibalization and price elasticity."

    # Slide 8 - stock control process
    $slide = New-StandardSlide -Presentation $presentation -Title 'Every stock change follows one controlled path' -Kicker 'PROCESS INFOGRAPHIC' -Subtitle 'Balances are derived from an append-only movement ledger; users never edit stock totals directly.'
    $events = @(
        @{ Y=143; Label='Opening / adjustment'; Color=$colors.Blue },
        @{ Y=205; Label='Goods receipt'; Color=$colors.Purple },
        @{ Y=267; Label='Transfer dispatch / receipt'; Color=$colors.Teal },
        @{ Y=329; Label='Confirmed sale / return'; Color=$colors.Green }
    )
    foreach ($event in $events) {
        $null = Add-Box -Slide $slide -Left 48 -Top $event.Y -Width 205 -Height 44 -Fill $colors.White -Line $event.Color -Radius 12
        $null = Add-Text -Slide $slide -Text $event.Label -Left 61 -Top ($event.Y + 5) -Width 178 -Height 34 -Size 11 -Color $colors.Navy -Bold $true
        $null = Add-Line -Slide $slide -X1 253 -Y1 ($event.Y + 22) -X2 334 -Y2 273 -Color $event.Color -Weight 2.2
    }
    $null = Add-Box -Slide $slide -Left 337 -Top 194 -Width 244 -Height 158 -Fill $colors.Navy -Line $colors.Navy -Radius 18 -Shadow $true
    $null = Add-Badge -Slide $slide -Text 'LOG' -Left 432 -Top 215 -Diameter 54 -Fill $colors.Teal -Size 10
    $null = Add-Text -Slide $slide -Text 'Append-only stock movement ledger' -Left 367 -Top 278 -Width 184 -Height 54 -Size 16 -Color $colors.White -Bold $true -Align 2
    $null = Add-Line -Slide $slide -X1 581 -Y1 236 -X2 679 -Y2 181 -Color $colors.Blue -Weight 2.5
    $null = Add-Line -Slide $slide -X1 581 -Y1 273 -X2 679 -Y2 273 -Color $colors.Teal -Weight 2.5
    $null = Add-Line -Slide $slide -X1 581 -Y1 310 -X2 679 -Y2 366 -Color $colors.Purple -Weight 2.5
    $outputs = @(
        @{ Y=143; Title='Inventory balance'; Body='On hand, reserved, available, incoming'; Color=$colors.Blue; Soft=$colors.BlueSoft },
        @{ Y=235; Title='FIFO batches'; Body='Remaining quantity, unit cost and age'; Color=$colors.Teal; Soft=$colors.TealSoft },
        @{ Y=327; Title='Daily snapshots'; Body='Opening, closing, demand, stockout minutes'; Color=$colors.Purple; Soft=$colors.PurpleSoft }
    )
    foreach ($output in $outputs) {
        $null = Add-Box -Slide $slide -Left 683 -Top $output.Y -Width 229 -Height 76 -Fill $colors.White -Line $output.Color -Radius 12
        $null = Add-Box -Slide $slide -Left 683 -Top $output.Y -Width 8 -Height 76 -Fill $output.Color -Line $output.Color -Radius 4
        $null = Add-Text -Slide $slide -Text $output.Title -Left 705 -Top ($output.Y + 10) -Width 184 -Height 24 -Size 12.5 -Color $colors.Navy -Bold $true -VerticalAlign 1
        $null = Add-Text -Slide $slide -Text $output.Body -Left 705 -Top ($output.Y + 35) -Width 184 -Height 31 -Size 9 -Color $colors.Muted -VerticalAlign 1
    }
    $guards = @('Transactional updates', 'Negative stock blocked', 'Weighted average + FIFO cost')
    for ($i=0; $i -lt $guards.Count; $i++) {
        $null = Add-Pill -Slide $slide -Text $guards[$i] -Left (126 + ($i * 252)) -Top 436 -Width 220 -Height 33 -Fill $colors.GreenSoft -Color $colors.Green -Size 9.5
    }
    Add-Footer -Slide $slide -Number 8
    Add-Notes -Slide $slide -Text "This is the integrity backbone. Receipts, sales, returns, adjustments and transfers do not rewrite a balance directly. Each creates ledger entries inside a database transaction. The balance and FIFO batch records are updated from the same event. Outbound actions that would make stock negative are rejected. Daily snapshots then convert this audited event history into forecasting data."

    # Slide 9 - workflow states
    $slide = New-StandardSlide -Presentation $presentation -Title 'Operational workflows protect stock integrity' -Kicker 'STATUS LIFECYCLES' -Subtitle 'Status changes are business controls: they define when stock and financial values become final.'
    $lanes = @(
        @{ Y=150; Label='PURCHASE ORDER'; Color=$colors.Purple; Steps=@('Draft','Approved','Ordered','Partially received','Received'); Note='Only Draft can be edited or deleted. Cancel before stock arrives.' },
        @{ Y=265; Label='STOCK TRANSFER'; Color=$colors.Teal; Steps=@('Draft','Approved','Dispatched','Received'); Note='Source decreases at dispatch; destination increases at receipt.' },
        @{ Y=380; Label='SALES ORDER'; Color=$colors.Green; Steps=@('Draft','Confirmed','Return if needed'); Note='Confirmed sale consumes FIFO; damaged returns are logged in and out.' }
    )
    foreach ($lane in $lanes) {
        $null = Add-Pill -Slide $slide -Text $lane.Label -Left 48 -Top $lane.Y -Width 142 -Height 34 -Fill $lane.Color -Color $colors.White -Size 9
        $stepCount = $lane.Steps.Count
        $available = 650
        $stepWidth = [Math]::Min(126, (($available - (($stepCount - 1) * 22)) / $stepCount))
        $x = 214
        for ($i=0; $i -lt $stepCount; $i++) {
            $null = Add-Box -Slide $slide -Left $x -Top ($lane.Y - 2) -Width $stepWidth -Height 38 -Fill $colors.White -Line $lane.Color -Radius 12
            $null = Add-Text -Slide $slide -Text $lane.Steps[$i] -Left ($x + 4) -Top ($lane.Y + 1) -Width ($stepWidth - 8) -Height 30 -Size 9.5 -Color $colors.Navy -Bold $true -Align 2
            if ($i -lt ($stepCount - 1)) {
                $null = Add-Line -Slide $slide -X1 ($x + $stepWidth) -Y1 ($lane.Y + 17) -X2 ($x + $stepWidth + 19) -Y2 ($lane.Y + 17) -Color $lane.Color -Weight 1.7
            }
            $x += $stepWidth + 22
        }
        $null = Add-Text -Slide $slide -Text $lane.Note -Left 214 -Top ($lane.Y + 43) -Width 670 -Height 28 -Size 9.5 -Color $colors.Muted -VerticalAlign 1
    }
    Add-Footer -Slide $slide -Number 9
    Add-Notes -Slide $slide -Text "Explain when stock actually moves. A purchase order creates incoming expectations; the posted goods receipt creates inventory. A transfer deducts at dispatch and adds at receipt. A confirmed sale consumes FIFO and captures actual cost. Posted receipts and returns are immutable so their stock consequences remain auditable."

    # Slide 10 - analytics
    $slide = New-StandardSlide -Presentation $presentation -Title 'Analytics turns inventory into management questions' -Kicker 'DETERMINISTIC ANALYTICS' -Subtitle 'Calculated on request from stock and sales history - read-only, transparent and independent of ML.'
    $metrics = @(
        @{ X=48; Y=142; Code='V'; Title='Daily velocity'; Body='How quickly does it sell?'; Color=$colors.Blue },
        @{ X=270; Y=142; Code='D'; Title='Days of stock'; Body='How long until run-out?'; Color=$colors.Teal },
        @{ X=492; Y=142; Code='T'; Title='Turnover'; Body='How productively is stock used?'; Color=$colors.Purple },
        @{ X=714; Y=142; Code='A'; Title='Stock age'; Body='How long has cash been sitting?'; Color=$colors.Amber },
        @{ X=159; Y=284; Code='ABC'; Title='Revenue tier'; Body='Which items matter most to revenue?'; Color=$colors.Blue },
        @{ X=381; Y=284; Code='SPD'; Title='Movement class'; Body='Fast, slow or dead mover?'; Color=$colors.Green },
        @{ X=603; Y=284; Code='ROP'; Title='Reorder point'; Body='Will available stock cover lead time + safety?'; Color=$colors.Red }
    )
    foreach ($metric in $metrics) {
        $null = Add-Box -Slide $slide -Left $metric.X -Top $metric.Y -Width 198 -Height 112 -Fill $colors.White -Line $colors.Border -Radius 14 -Shadow $true
        $null = Add-Badge -Slide $slide -Text $metric.Code -Left ($metric.X + 14) -Top ($metric.Y + 15) -Diameter 34 -Fill $metric.Color -Size $(if ($metric.Code.Length -gt 1) { 7.5 } else { 12 })
        $null = Add-Text -Slide $slide -Text $metric.Title -Left ($metric.X + 58) -Top ($metric.Y + 14) -Width 126 -Height 35 -Size 12.5 -Color $colors.Navy -Bold $true
        $null = Add-Text -Slide $slide -Text $metric.Body -Left ($metric.X + 17) -Top ($metric.Y + 62) -Width 164 -Height 38 -Size 9.5 -Color $colors.Muted -Align 2 -VerticalAlign 1
    }
    $null = Add-Pill -Slide $slide -Text 'Filters: warehouse | category | SKU | ABC | mover | reorder need | lookback | safety days' -Left 127 -Top 438 -Width 706 -Height 34 -Fill $colors.Navy -Color $colors.White -Size 9.5
    Add-Footer -Slide $slide -Number 10
    Add-Notes -Slide $slide -Text "This page answers operational questions without a forecasting model. Velocity and turnover describe movement. Days of stock describes coverage. FIFO batch age exposes ageing capital. ABC and movement classes prioritize attention. The reorder point uses supplier lead time plus configurable safety days. The user can change the lookback and safety assumptions without changing any transaction."

    # Slide 11 - forecast pipeline
    $slide = New-StandardSlide -Presentation $presentation -Title 'How the forecast is produced today' -Kicker 'PROCESS INFOGRAPHIC' -Subtitle 'The system chooses the demand source and algorithm per SKU, records what actually ran, and scores it after the horizon closes.'
    $stageXs = @(36, 185, 334, 483, 632, 781)
    $stageData = @(
        @{ Code='1'; Title='INPUTS'; Body="Daily demand`r`nStockouts`r`nPrice & promotion`r`nSKU metadata"; Color=$colors.Blue },
        @{ Code='2'; Title='MATURITY'; Body="Cold start`r`nEarly`r`nEstablished`r`nMature / declining"; Color=$colors.Teal },
        @{ Code='3'; Title='SOURCE'; Body="Own history`r`nCategory + size`r`nBrand + category`r`nCategory / hybrid"; Color=$colors.Blue },
        @{ Code='4'; Title='ALGORITHM'; Body="EWMA`r`nSeasonal naive`r`nTFT`r`nDeepAR"; Color=$colors.Purple },
        @{ Code='5'; Title='OUTPUT'; Body="Quantity`r`nLower / upper`r`nConfidence`r`nActual algorithm"; Color=$colors.Blue },
        @{ Code='6'; Title='LEARN'; Body="Wait for horizon`r`nCompare actuals`r`nScore accuracy`r`nRank next run"; Color=$colors.Green }
    )
    for ($i=0; $i -lt $stageData.Count; $i++) {
        $x = $stageXs[$i]
        if ($i -lt ($stageData.Count - 1)) {
            $null = Add-Line -Slide $slide -X1 ($x + 133) -Y1 283 -X2 ($stageXs[$i+1] - 7) -Y2 283 -Color $stageData[$i].Color -Weight 2.3
        }
        $null = Add-Box -Slide $slide -Left $x -Top 155 -Width 132 -Height 255 -Fill $colors.White -Line $stageData[$i].Color -Radius 14 -Shadow $true
        $null = Add-Badge -Slide $slide -Text $stageData[$i].Code -Left ($x + 47) -Top 172 -Diameter 38 -Fill $stageData[$i].Color
        $null = Add-Text -Slide $slide -Text $stageData[$i].Title -Left ($x + 10) -Top 219 -Width 112 -Height 28 -Size 11 -Color $stageData[$i].Color -Bold $true -Align 2
        $null = Add-Text -Slide $slide -Text $stageData[$i].Body -Left ($x + 10) -Top 258 -Width 112 -Height 132 -Size 9.5 -Color $colors.Slate -Align 2 -VerticalAlign 1
    }
    $null = Add-Pill -Slide $slide -Text 'Default: EWMA until scored accuracy history exists' -Left 70 -Top 438 -Width 352 -Height 34 -Fill $colors.AmberSoft -Color $colors.Amber -Size 9.5
    $null = Add-Pill -Slide $slide -Text 'Neural refusal -> safe baseline fallback, per SKU' -Left 438 -Top 438 -Width 418 -Height 34 -Fill $colors.GreenSoft -Color $colors.Green -Size 9.5
    Add-Footer -Slide $slide -Number 11
    Add-Notes -Slide $slide -Text "Forecasting is a two-stage selection problem. First, maturity determines whether the SKU's own history is trustworthy or whether a peer profile is needed. Second, the model selector chooses among EWMA, seasonal naive, TFT and DeepAR using scored accuracy when available. Until accuracy history exists, configuration chooses the default; it is currently EWMA. If a neural model cannot honestly serve a pair, Python returns a baseline and Laravel records the algorithm that actually ran."

    # Slide 12 - model evidence
    $slide = New-StandardSlide -Presentation $presentation -Title 'The model is operational; its business accuracy is not yet proven' -Kicker 'MODEL EVIDENCE & GOVERNANCE' -Subtitle 'Lower WAPE is better. These results are pipeline evidence from generated history, not a production forecast claim.'
    $null = Add-Box -Slide $slide -Left 48 -Top 137 -Width 480 -Height 316 -Fill $colors.White -Line $colors.Border -Radius 16 -Shadow $true
    $null = Add-Text -Slide $slide -Text 'Most recent honest 30-day window' -Left 69 -Top 154 -Width 390 -Height 28 -Size 14 -Color $colors.Navy -Bold $true
    $bars = @(
        @{ Name='EWMA'; Value=32.14; Color=$colors.Green },
        @{ Name='Seasonal naive'; Value=32.43; Color=$colors.Teal },
        @{ Name='TFT'; Value=35.35; Color=$colors.Purple },
        @{ Name='DeepAR'; Value=37.49; Color=$colors.Red }
    )
    for ($i=0; $i -lt $bars.Count; $i++) {
        $y = 205 + ($i * 56)
        $null = Add-Text -Slide $slide -Text $bars[$i].Name -Left 70 -Top ($y - 3) -Width 104 -Height 24 -Size 10 -Color $colors.Slate -Bold $true -Align 3
        $null = Add-Box -Slide $slide -Left 184 -Top $y -Width 272 -Height 18 -Fill $colors.Canvas -Line $colors.Canvas -Radius 8
        $width = ($bars[$i].Value / 40.0) * 272
        $null = Add-Box -Slide $slide -Left 184 -Top $y -Width $width -Height 18 -Fill $bars[$i].Color -Line $bars[$i].Color -Radius 8
        $null = Add-Text -Slide $slide -Text (("{0:N2}%" -f $bars[$i].Value)) -Left 463 -Top ($y - 4) -Width 51 -Height 26 -Size 9 -Color $colors.Navy -Bold $true -Align 3
    }
    $null = Add-Text -Slide $slide -Text 'EWMA currently wins this checkpoint window.' -Left 184 -Top 425 -Width 320 -Height 22 -Size 9.5 -Color $colors.Green -Bold $true
    $null = Add-Box -Slide $slide -Left 552 -Top 137 -Width 360 -Height 316 -Fill $colors.Navy -Line $colors.Navy -Radius 16 -Shadow $true
    $null = Add-Text -Slide $slide -Text 'What is working now' -Left 579 -Top 157 -Width 300 -Height 30 -Size 15 -Color $colors.White -Bold $true
    $facts = @(
        @{ Big='441'; Small='warehouse / SKU pairs processed' },
        @{ Big='404'; Small='TFT forecasts in a real seeded run' },
        @{ Big='37'; Small='safe baseline fallbacks' },
        @{ Big='~20s'; Small='warm end-to-end neural run' }
    )
    for ($i=0; $i -lt $facts.Count; $i++) {
        $row = [Math]::Floor($i / 2)
        $col = $i % 2
        $x = 579 + ($col * 150)
        $y = 211 + ($row * 82)
        $null = Add-Text -Slide $slide -Text $facts[$i].Big -Left $x -Top $y -Width 132 -Height 35 -Size 22 -Color $(if ($i -eq 1) { $colors.PurpleSoft } else { $colors.White }) -Bold $true
        $null = Add-Text -Slide $slide -Text $facts[$i].Small -Left $x -Top ($y + 36) -Width 132 -Height 36 -Size 8.5 -Color $colors.LightMuted -VerticalAlign 1
    }
    $null = Add-Box -Slide $slide -Left 579 -Top 374 -Width 306 -Height 59 -Fill $colors.Amber -Line $colors.Amber -Radius 12
    $null = Add-Text -Slide $slide -Text 'Decision: keep EWMA as default until real sales history validates a neural promotion.' -Left 592 -Top 381 -Width 280 -Height 45 -Size 10 -Color $colors.Navy -Bold $true -Align 2
    $null = Add-Pill -Slide $slide -Text 'A fresh TFT led the best baseline by about 7.8% in the first three rolling windows - but on synthetic history.' -Left 110 -Top 470 -Width 740 -Height 31 -Fill $colors.PurpleSoft -Color $colors.Purple -Size 8.8
    Add-Footer -Slide $slide -Number 12
    Add-Notes -Slide $slide -Text "Be precise here. The neural serving path works and was demonstrated at full seeded scale. The accuracy decision is separate. On the latest scorable 30-day window, both baselines beat TFT and DeepAR, so EWMA remains the configured default. A broader rolling experiment found a fresh TFT about 7.8 percent better for the first three windows, but the training history is generated. Management should treat the neural result as technical readiness, not production business evidence."

    # Slide 13 - recommendation engine
    $slide = New-StandardSlide -Presentation $presentation -Title 'A forecast becomes one of five controlled actions' -Kicker 'PROCESS INFOGRAPHIC' -Subtitle 'The decision engine combines demand, supply, ordering constraints, ageing and location before asking a person to decide.'
    $inputLabels = @('Latest forecast','On hand + incoming','Lead time + safety','MOQ + order multiple','FIFO age + overstock','Other warehouse stock')
    for ($i=0; $i -lt $inputLabels.Count; $i++) {
        $y = 145 + ($i * 47)
        $null = Add-Box -Slide $slide -Left 48 -Top $y -Width 205 -Height 35 -Fill $colors.White -Line $colors.Blue -Radius 10
        $null = Add-Text -Slide $slide -Text $inputLabels[$i] -Left 58 -Top ($y + 2) -Width 185 -Height 29 -Size 9.5 -Color $colors.Navy -Bold $true
        $null = Add-Line -Slide $slide -X1 253 -Y1 ($y + 17) -X2 344 -Y2 282 -Color $colors.Blue -Weight 1.7
    }
    $null = Add-Box -Slide $slide -Left 348 -Top 197 -Width 206 -Height 171 -Fill $colors.Navy -Line $colors.Navy -Radius 18 -Shadow $true
    $null = Add-Badge -Slide $slide -Text 'RULES' -Left 423 -Top 221 -Diameter 55 -Fill $colors.Purple -Size 9
    $null = Add-Text -Slide $slide -Text 'Inventory decision engine' -Left 379 -Top 293 -Width 144 -Height 50 -Size 15 -Color $colors.White -Bold $true -Align 2
    $actions = @(
        @{ Y=137; Text='Purchase'; Color=$colors.Purple; Soft=$colors.PurpleSoft },
        @{ Y=191; Text='Reduce purchase'; Color=$colors.Amber; Soft=$colors.AmberSoft },
        @{ Y=245; Text='Do not reorder'; Color=$colors.Red; Soft=$colors.RedSoft },
        @{ Y=299; Text='Transfer stock'; Color=$colors.Teal; Soft=$colors.TealSoft },
        @{ Y=353; Text='Clearance'; Color=$colors.Green; Soft=$colors.GreenSoft }
    )
    foreach ($action in $actions) {
        $null = Add-Line -Slide $slide -X1 554 -Y1 282 -X2 636 -Y2 ($action.Y + 19) -Color $action.Color -Weight 1.7
        $null = Add-Box -Slide $slide -Left 640 -Top $action.Y -Width 179 -Height 39 -Fill $action.Soft -Line $action.Color -Radius 11
        $null = Add-Text -Slide $slide -Text $action.Text -Left 650 -Top ($action.Y + 3) -Width 159 -Height 31 -Size 10.5 -Color $action.Color -Bold $true -Align 2
    }
    $null = Add-Line -Slide $slide -X1 821 -Y1 282 -X2 865 -Y2 282 -Color $colors.Green -Weight 2.5
    $null = Add-Box -Slide $slide -Left 860 -Top 210 -Width 72 -Height 145 -Fill $colors.GreenSoft -Line $colors.Green -Radius 14
    $null = Add-Text -Slide $slide -Text "HUMAN`r`nREVIEW`r`n`r`nAccept`r`nModify`r`nReject" -Left 867 -Top 220 -Width 58 -Height 126 -Size 9 -Color $colors.Green -Bold $true -Align 2 -VerticalAlign 3
    $null = Add-Pill -Slide $slide -Text 'Accepted decisions are executed through normal PO or transfer workflows - never silently auto-posted.' -Left 151 -Top 450 -Width 658 -Height 35 -Fill $colors.GreenSoft -Color $colors.Green -Size 9.5
    Add-Footer -Slide $slide -Number 13
    Add-Notes -Slide $slide -Text "The engine does more than a simple reorder point. It looks at current and incoming stock, forecast demand, supplier lead time, dynamic safety stock, order minimums and multiples, FIFO ageing, overstock risk and stock elsewhere. It proposes one of five actions. A person then accepts, modifies with a reason, or rejects. Even acceptance does not directly create stock; it feeds the normal purchasing or transfer control process."

    # Slide 14 - multi-location
    $slide = New-StandardSlide -Presentation $presentation -Title 'Optimize across warehouses before buying more' -Kicker 'MULTI-LOCATION DECISION FLOW' -Subtitle 'Stock transfer matching reduces avoidable purchasing; central allocation consolidates the remaining need.'
    $warehouseCards = @(
        @{ X=56; Title='Warehouse A'; Qty='+42 surplus'; Color=$colors.Green; Soft=$colors.GreenSoft },
        @{ X=56; Title='Warehouse B'; Qty='-18 shortage'; Color=$colors.Red; Soft=$colors.RedSoft },
        @{ X=56; Title='Warehouse C'; Qty='-12 shortage'; Color=$colors.Red; Soft=$colors.RedSoft }
    )
    for ($i=0; $i -lt $warehouseCards.Count; $i++) {
        $y = 150 + ($i * 95)
        $card = $warehouseCards[$i]
        $null = Add-Box -Slide $slide -Left $card.X -Top $y -Width 195 -Height 70 -Fill $card.Soft -Line $card.Color -Radius 14
        $null = Add-Text -Slide $slide -Text $card.Title -Left ($card.X + 18) -Top ($y + 10) -Width 160 -Height 24 -Size 12 -Color $colors.Navy -Bold $true
        $null = Add-Text -Slide $slide -Text $card.Qty -Left ($card.X + 18) -Top ($y + 36) -Width 160 -Height 23 -Size 10.5 -Color $card.Color -Bold $true
    }
    $null = Add-Line -Slide $slide -X1 252 -Y1 185 -X2 348 -Y2 245 -Color $colors.Green -Weight 3
    $null = Add-Line -Slide $slide -X1 252 -Y1 280 -X2 348 -Y2 292 -Color $colors.Red -Weight 2.4
    $null = Add-Line -Slide $slide -X1 252 -Y1 375 -X2 348 -Y2 339 -Color $colors.Red -Weight 2.4
    $null = Add-Box -Slide $slide -Left 350 -Top 197 -Width 220 -Height 190 -Fill $colors.Navy -Line $colors.Navy -Radius 18 -Shadow $true
    $null = Add-Text -Slide $slide -Text 'TRANSFER MATCHING' -Left 371 -Top 220 -Width 178 -Height 28 -Size 12.5 -Color $colors.White -Bold $true -Align 2
    $null = Add-Text -Slide $slide -Text "Use another warehouse first when one source can cover the recipient's full need.`r`n`r`nNo route or transport-cost optimization yet." -Left 374 -Top 260 -Width 172 -Height 101 -Size 10 -Color $colors.LightMuted -Align 2 -VerticalAlign 1
    $null = Add-Line -Slide $slide -X1 570 -Y1 292 -X2 659 -Y2 292 -Color $colors.Purple -Weight 3
    $null = Add-Box -Slide $slide -Left 664 -Top 171 -Width 240 -Height 242 -Fill $colors.White -Line $colors.Purple -Radius 18 -Shadow $true
    $null = Add-Badge -Slide $slide -Text 'BUY' -Left 757 -Top 193 -Diameter 54 -Fill $colors.Purple -Size 10
    $null = Add-Text -Slide $slide -Text 'CENTRAL ALLOCATION' -Left 692 -Top 260 -Width 184 -Height 28 -Size 13 -Color $colors.Purple -Bold $true -Align 2
    $null = Add-Text -Slide $slide -Text "For SKUs still needed in two or more warehouses:`r`n`r`n- combine the quantity`r`n- retain warehouse breakdown`r`n- place a consolidated supplier order" -Left 692 -Top 300 -Width 184 -Height 96 -Size 9.5 -Color $colors.Slate -Align 2 -VerticalAlign 1
    $null = Add-Pill -Slide $slide -Text 'Business effect: rebalance owned stock before committing more working capital' -Left 194 -Top 446 -Width 570 -Height 36 -Fill $colors.TealSoft -Color $colors.Teal -Size 10
    Add-Footer -Slide $slide -Number 14
    Add-Notes -Slide $slide -Text "The current optimization is intentionally understandable. If another warehouse has enough surplus to cover a recipient's full need, the system proposes a transfer before a purchase. After transfers, purchase-type recommendations for the same SKU across at least two warehouses are rolled into a central allocation view. This is not yet a transport-cost or multi-source optimization solver."

    # Slide 15 - advanced intelligence
    $slide = New-StandardSlide -Presentation $presentation -Title 'Seven additional signals expand the management picture' -Kicker 'ADVANCED INTELLIGENCE' -Subtitle 'Each view is independent, explainable and computed from operational history.'
    $signals = @(
        @{ X=48; Y=141; Code='SUP'; Title='Supplier performance'; Body='Actual lead time, on-time delivery and fill rate'; Color=$colors.Teal },
        @{ X=270; Y=141; Code='LOST'; Title='Lost-sales estimate'; Body='Likely missed units during real stockouts'; Color=$colors.Red },
        @{ X=492; Y=141; Code='ANOM'; Title='Demand anomalies'; Body='Statistical demand outliers by SKU and date'; Color=$colors.Purple },
        @{ X=714; Y=141; Code='SIM'; Title='Product similarity'; Body='Comparable items from product attributes'; Color=$colors.Blue },
        @{ X=159; Y=294; Code='REL'; Title='Cannibalization & successor'; Body='Products that move against or replace each other'; Color=$colors.Amber },
        @{ X=381; Y=294; Code='ELAS'; Title='Price elasticity'; Body='Demand response across actual price points'; Color=$colors.Green },
        @{ X=603; Y=294; Code='PROMO'; Title='Promotion impact'; Body='Before-vs-during demand by promotion and SKU'; Color=$colors.Purple }
    )
    foreach ($signal in $signals) {
        $null = Add-Box -Slide $slide -Left $signal.X -Top $signal.Y -Width 198 -Height 122 -Fill $colors.White -Line $colors.Border -Radius 14 -Shadow $true
        $null = Add-Badge -Slide $slide -Text $signal.Code -Left ($signal.X + 13) -Top ($signal.Y + 15) -Diameter 36 -Fill $signal.Color -Size 6.8
        $null = Add-Text -Slide $slide -Text $signal.Title -Left ($signal.X + 57) -Top ($signal.Y + 12) -Width 127 -Height 40 -Size 11 -Color $colors.Navy -Bold $true
        $null = Add-Text -Slide $slide -Text $signal.Body -Left ($signal.X + 16) -Top ($signal.Y + 67) -Width 166 -Height 43 -Size 8.8 -Color $colors.Muted -Align 2 -VerticalAlign 1
    }
    $null = Add-Pill -Slide $slide -Text 'Interpret low-sample signals carefully: availability is not the same as statistical certainty' -Left 200 -Top 455 -Width 560 -Height 32 -Fill $colors.AmberSoft -Color $colors.Amber -Size 9
    Add-Footer -Slide $slide -Number 15
    Add-Notes -Slide $slide -Text "Describe these as management signals, not guaranteed causes. Supplier performance and promotion impact have concrete business sources. Lost sales and anomalies are estimates. Similarity, cannibalization and elasticity are useful for investigation, but can be unstable when history is thin. The UI exposes counts and evidence so users can judge confidence instead of treating every number as an instruction."

    # Slide 16 - automation timeline
    $slide = New-StandardSlide -Presentation $presentation -Title 'Automation keeps history, accuracy and decisions current' -Kicker 'PROCESS INFOGRAPHIC' -Subtitle 'The queue worker and scheduler are operational dependencies; without them, the application appears healthy while intelligence becomes stale.'
    $null = Add-Text -Slide $slide -Text 'EVERY DAY' -Left 48 -Top 143 -Width 100 -Height 24 -Size 10 -Color $colors.Blue -Bold $true
    $null = Add-Line -Slide $slide -X1 121 -Y1 248 -X2 842 -Y2 248 -Color $colors.Blue -Weight 4 -Arrow $false
    $daily = @(
        @{ X=126; Time='00:15'; Title='Capture snapshots'; Body='Previous day balances, demand and stockout minutes'; Color=$colors.Teal },
        @{ X=375; Time='00:30'; Title='Score due forecasts'; Body='Compare elapsed horizons with actual demand'; Color=$colors.Purple },
        @{ X=624; Time='00:45'; Title='Generate recommendations'; Body='Refresh purchase, transfer and ageing actions'; Color=$colors.Green }
    )
    foreach ($item in $daily) {
        $null = Add-Badge -Slide $slide -Text $item.Time -Left $item.X -Top 224 -Diameter 48 -Fill $item.Color -Size 7.5
        $null = Add-Box -Slide $slide -Left ($item.X - 48) -Top 291 -Width 188 -Height 92 -Fill $colors.White -Line $item.Color -Radius 12 -Shadow $true
        $null = Add-Text -Slide $slide -Text $item.Title -Left ($item.X - 37) -Top 302 -Width 166 -Height 27 -Size 11 -Color $colors.Navy -Bold $true -Align 2
        $null = Add-Text -Slide $slide -Text $item.Body -Left ($item.X - 36) -Top 335 -Width 164 -Height 39 -Size 8.5 -Color $colors.Muted -Align 2 -VerticalAlign 1
    }
    $null = Add-Text -Slide $slide -Text 'FIRST DAY OF EACH MONTH' -Left 48 -Top 413 -Width 180 -Height 24 -Size 10 -Color $colors.Purple -Bold $true
    $null = Add-Pill -Slide $slide -Text '01:00 - Capture supplier performance' -Left 244 -Top 407 -Width 272 -Height 34 -Fill $colors.TealSoft -Color $colors.Teal -Size 9
    $null = Add-Pill -Slide $slide -Text '02:00 - Retrain neural models in background' -Left 535 -Top 407 -Width 337 -Height 34 -Fill $colors.PurpleSoft -Color $colors.Purple -Size 9
    $null = Add-Pill -Slide $slide -Text 'Manual buttons remain available for controlled backfill and on-demand runs' -Left 203 -Top 463 -Width 555 -Height 31 -Fill $colors.Navy -Color $colors.White -Size 9
    Add-Footer -Slide $slide -Number 16
    Add-Notes -Slide $slide -Text "The daily sequence matters. Snapshots run first so yesterday's actual demand exists. Accuracy scoring runs next. Recommendations run last using the newest history and scores. Supplier performance and neural retraining run monthly. In production, a host cron must invoke Laravel's scheduler, the queue worker must be supervised, and the Python service must be available. Manual controls exist for backfills and checks."

    # Slide 17 - architecture
    $slide = New-StandardSlide -Presentation $presentation -Title 'The system is one product across two runtimes' -Kicker 'TECHNICAL ARCHITECTURE' -Subtitle 'Laravel owns business truth and workflow; Python serves statistical and neural forecasts through a narrow HTTP contract.'
    $layerData = @(
        @{ X=40; W=138; Title='USERS'; Body="Browser`r`nLogin + verification`r`n2FA + passkeys"; Color=$colors.Blue; Soft=$colors.BlueSoft },
        @{ X=206; W=183; Title='WEB APPLICATION'; Body="Inertia v3 + React 19`r`nBootstrap 5.3 + SCSS`r`nTyped Wayfinder routes"; Color=$colors.Purple; Soft=$colors.PurpleSoft },
        @{ X=417; W=200; Title='LARAVEL 13'; Body="Form Request -> Controller`r`nFacade -> Service -> Model`r`nTransactions + validation"; Color=$colors.Blue; Soft=$colors.BlueSoft },
        @{ X=645; W=137; Title='MYSQL'; Body="Operational tables`r`nLedger + snapshots`r`nForecasts + decisions"; Color=$colors.Teal; Soft=$colors.TealSoft },
        @{ X=810; W=110; Title='PYTHON'; Body="FastAPI`r`nEWMA / seasonal`r`nTFT / DeepAR"; Color=$colors.Purple; Soft=$colors.PurpleSoft }
    )
    for ($i=0; $i -lt $layerData.Count; $i++) {
        $layer = $layerData[$i]
        if ($i -lt ($layerData.Count - 1)) {
            $null = Add-Line -Slide $slide -X1 ($layer.X + $layer.W) -Y1 256 -X2 ($layerData[$i+1].X - 8) -Y2 256 -Color $layer.Color -Weight 2.4
        }
        $null = Add-Box -Slide $slide -Left $layer.X -Top 158 -Width $layer.W -Height 196 -Fill $colors.White -Line $layer.Color -Radius 14 -Shadow $true
        $null = Add-Box -Slide $slide -Left $layer.X -Top 158 -Width $layer.W -Height 39 -Fill $layer.Color -Line $layer.Color -Radius 10
        $null = Add-Text -Slide $slide -Text $layer.Title -Left ($layer.X + 7) -Top 164 -Width ($layer.W - 14) -Height 26 -Size 10.5 -Color $colors.White -Bold $true -Align 2
        $null = Add-Text -Slide $slide -Text $layer.Body -Left ($layer.X + 11) -Top 216 -Width ($layer.W - 22) -Height 118 -Size 9.1 -Color $colors.Slate -Align 2 -VerticalAlign 1
    }
    $null = Add-Line -Slide $slide -X1 516 -Y1 354 -X2 516 -Y2 407 -Color $colors.Green -Weight 2.2
    $null = Add-Pill -Slide $slide -Text 'Database queue worker: forecast jobs' -Left 342 -Top 412 -Width 273 -Height 34 -Fill $colors.GreenSoft -Color $colors.Green -Size 9
    $null = Add-Line -Slide $slide -X1 714 -Y1 354 -X2 714 -Y2 407 -Color $colors.Amber -Weight 2.2
    $null = Add-Pill -Slide $slide -Text 'Scheduler: daily + monthly sequence' -Left 631 -Top 412 -Width 274 -Height 34 -Fill $colors.AmberSoft -Color $colors.Amber -Size 9
    $null = Add-Pill -Slide $slide -Text 'Python is separate: supervise it and require bearer-token auth before network exposure' -Left 166 -Top 464 -Width 629 -Height 31 -Fill $colors.RedSoft -Color $colors.Red -Size 8.8
    Add-Footer -Slide $slide -Number 17
    Add-Notes -Slide $slide -Text "The browser sees one application. React and Inertia render the experience. Laravel remains the source of business truth and enforces the project's thin-controller, facade, service and model flow. MySQL stores all operational and intelligence records. Forecast jobs run through the queue and call a separate FastAPI service. Python never receives raw transaction tables; Laravel sends compact daily series and covariates."

    # Slide 18 - controls
    $slide = New-StandardSlide -Presentation $presentation -Title 'Controls delivered - and controls still required' -Kicker 'SECURITY & DATA INTEGRITY' -Subtitle 'The application protects individual accounts and stock transactions, but does not yet separate business duties by role.'
    $null = Add-Box -Slide $slide -Left 48 -Top 139 -Width 414 -Height 330 -Fill $colors.White -Line $colors.Green -Radius 16 -Shadow $true
    $null = Add-Pill -Slide $slide -Text 'DELIVERED CONTROLS' -Left 68 -Top 157 -Width 198 -Height 32 -Fill $colors.Green -Color $colors.White -Size 10
    $delivered = @(
        'Verified login, reset and password confirmation',
        'TOTP two-factor authentication and passkeys',
        'Login / 2FA / passkey rate limits',
        'Append-only ledger and immutable posted documents',
        'Transactional stock writes and negative-stock guard',
        'Server-calculated PO totals and FIFO sale cost'
    )
    for ($i=0; $i -lt $delivered.Count; $i++) {
        $null = Add-Badge -Slide $slide -Text 'OK' -Left 72 -Top (211 + ($i*40)) -Diameter 25 -Fill $colors.Green -Size 7
        $null = Add-Text -Slide $slide -Text $delivered[$i] -Left 106 -Top (208 + ($i*40)) -Width 330 -Height 30 -Size 9.6 -Color $colors.Slate -VerticalAlign 3
    }
    $null = Add-Box -Slide $slide -Left 498 -Top 139 -Width 414 -Height 330 -Fill $colors.White -Line $colors.Amber -Radius 16 -Shadow $true
    $null = Add-Pill -Slide $slide -Text 'BEFORE PRODUCTION' -Left 518 -Top 157 -Width 198 -Height 32 -Fill $colors.Amber -Color $colors.Navy -Size 10
    $required = @(
        'Role-based permissions and separation of duties',
        'Controlled registration / user provisioning policy',
        'Real email, HTTPS and production cookie settings',
        'Hosting, backup, restore and monitoring design',
        'Supervised queue, scheduler and Python processes',
        'Token-protect the Python service off localhost'
    )
    for ($i=0; $i -lt $required.Count; $i++) {
        $null = Add-Badge -Slide $slide -Text '!' -Left 522 -Top (211 + ($i*40)) -Diameter 25 -Fill $colors.Amber -Color $colors.Navy -Size 11
        $null = Add-Text -Slide $slide -Text $required[$i] -Left 556 -Top (208 + ($i*40)) -Width 330 -Height 30 -Size 9.6 -Color $colors.Slate -VerticalAlign 3
    }
    Add-Footer -Slide $slide -Number 18
    Add-Notes -Slide $slide -Text "Separate account security from authorization. The application has strong authentication options and rate limits. Its stock writes are also carefully controlled. The production blocker is role-based authorization: every verified user currently has broad business access. Management must define who maintains master data, posts operations, reviews recommendations and administers users before the application is exposed."

    # Slide 19 - evidence
    $slide = New-StandardSlide -Presentation $presentation -Title 'Delivery evidence shows breadth and working integration' -Kicker 'ASSURANCE SNAPSHOT' -Subtitle 'Counts describe the current source tree and the latest fully logged end-to-end verification.'
    $facts = @(
        @{ X=48; Y=145; Big='10'; Label='delivery phases implemented'; Color=$colors.Purple },
        @{ X=263; Y=145; Big='254/254'; Label='Pest tests passed'; Color=$colors.Green },
        @{ X=478; Y=145; Big='62/62'; Label='Python tests passed'; Color=$colors.Teal },
        @{ X=693; Y=145; Big='31'; Label='Facade + service module pairs'; Color=$colors.Blue },
        @{ X=48; Y=288; Big='33'; Label='Eloquent models'; Color=$colors.Blue },
        @{ X=263; Y=288; Big='38'; Label='database migrations'; Color=$colors.Teal },
        @{ X=478; Y=288; Big='71'; Label='React page files'; Color=$colors.Purple },
        @{ X=693; Y=288; Big='197'; Label='recommendations generated in seeded run'; Color=$colors.Green }
    )
    foreach ($fact in $facts) {
        $null = Add-Box -Slide $slide -Left $fact.X -Top $fact.Y -Width 195 -Height 116 -Fill $colors.White -Line $colors.Border -Radius 14 -Shadow $true
        $null = Add-Text -Slide $slide -Text $fact.Big -Left ($fact.X+14) -Top ($fact.Y+20) -Width 167 -Height 43 -Size 25 -Color $fact.Color -Bold $true -Align 2
        $null = Add-Text -Slide $slide -Text $fact.Label -Left ($fact.X+14) -Top ($fact.Y+69) -Width 167 -Height 34 -Size 9 -Color $colors.Muted -Align 2 -VerticalAlign 1
    }
    $null = Add-Pill -Slide $slide -Text 'Latest end-to-end seeded run: 441 pairs -> 404 TFT forecasts + 37 safe fallbacks' -Left 173 -Top 434 -Width 614 -Height 36 -Fill $colors.Navy -Color $colors.White -Size 9.5
    $null = Add-Pill -Slide $slide -Text 'Known UI gap: dashboard KPI props remain unconnected and show honest empty states' -Left 195 -Top 477 -Width 570 -Height 28 -Fill $colors.AmberSoft -Color $colors.Amber -Size 8.7
    Add-Footer -Slide $slide -Number 19
    Add-Notes -Slide $slide -Text "These are engineering and integration assurance measures, not adoption KPIs. The latest logged checks passed 254 Pest tests and 62 Python tests. The real seeded pipeline processed 441 warehouse-SKU pairs and generated 197 recommendations. The dashboard remains a starter shell with empty metric props; detailed operational and intelligence pages contain the real implemented value today."

    # Slide 20 - production readiness infographic
    $slide = $presentation.Slides.Add($presentation.Slides.Count + 1, 12)
    $readinessImage = Join-Path $repositoryRoot 'docs\images\05-production-readiness.png'
    $null = Add-Picture -Slide $slide -Path $readinessImage -Left 0 -Top 0 -Width $slideWidth -Height $slideHeight
    Add-Notes -Slide $slide -Text "Summarize the handoff plainly: the functional controls are delivered, but go-live is an operating decision. The largest blocker is RBAC. The remaining items are production platform, backups, real mail, supervised workers and schedule, private token-protected Python hosting, monitoring and full release validation."

    # Slide 21 - operating rhythm and decisions
    $slide = New-StandardSlide -Presentation $presentation -Title 'Management ownership turns software into an operating capability' -Kicker 'OPERATING RHYTHM & NEXT DECISIONS' -Subtitle 'Use the system on a cadence, assign decision owners, and promote forecasting models only when real evidence supports it.'
    $rhythms = @(
        @{ X=48; Title='DAILY'; Color=$colors.Blue; Items="Review stockout / ageing exceptions`r`nCheck queued or failed forecast runs`r`nPost receipts, sales, returns and transfers" },
        @{ X=341; Title='WEEKLY'; Color=$colors.Teal; Items="Approve / modify recommendations`r`nReview open and partially received POs`r`nInvestigate lost sales and anomalies" },
        @{ X=634; Title='MONTHLY'; Color=$colors.Purple; Items="Review supplier scorecards`r`nReview forecast accuracy and model choice`r`nEvaluate promotions and data quality" }
    )
    foreach ($rhythm in $rhythms) {
        $null = Add-Box -Slide $slide -Left $rhythm.X -Top 145 -Width 278 -Height 167 -Fill $colors.White -Line $rhythm.Color -Radius 15 -Shadow $true
        $null = Add-Pill -Slide $slide -Text $rhythm.Title -Left ($rhythm.X+18) -Top 163 -Width 105 -Height 30 -Fill $rhythm.Color -Color $colors.White -Size 9.5
        $null = Add-Text -Slide $slide -Text $rhythm.Items -Left ($rhythm.X+20) -Top 211 -Width 238 -Height 82 -Size 9.8 -Color $colors.Slate -VerticalAlign 1
    }
    $null = Add-Text -Slide $slide -Text 'DECISIONS MANAGEMENT SHOULD MAKE NOW' -Left 48 -Top 340 -Width 360 -Height 26 -Size 11 -Color $colors.Navy -Bold $true
    $decisions = @(
        '1. Name data owners, transaction operators and recommendation approvers',
        '2. Implement RBAC and decide how users are provisioned',
        '3. Choose production hosting, MySQL/Redis, backups, mail and monitoring',
        '4. Operate queue, scheduler, Python service and monthly retraining',
        '5. Validate on real sales history before making TFT or DeepAR the default'
    )
    for ($i=0; $i -lt $decisions.Count; $i++) {
        $column = $i % 2
        $row = [Math]::Floor($i / 2)
        $x = 48 + ($column * 432)
        $y = 373 + ($row * 40)
        $width = $(if (($i -eq 4)) { 820 } else { 406 })
        $null = Add-Text -Slide $slide -Text $decisions[$i] -Left $x -Top $y -Width $width -Height 32 -Size 9.2 -Color $(if ($i -eq 4) { $colors.Purple } else { $colors.Slate }) -Bold $(if ($i -eq 4) { $true } else { $false }) -VerticalAlign 3
    }
    Add-Footer -Slide $slide -Number 21
    Add-Notes -Slide $slide -Text "Finish the management section with ownership. Daily work is exception-driven. Weekly work is approval and operational review. Monthly work is supplier, forecast, promotion and data-quality governance. The five decisions are the bridge to production. The most important analytical decision is to validate models on real history before changing the default away from EWMA."

    # Slide 22 - demo route and close
    $slide = $presentation.Slides.Add($presentation.Slides.Count + 1, 12)
    $slide.FollowMasterBackground = 0
    $slide.Background.Fill.Solid()
    $slide.Background.Fill.ForeColor.RGB = $colors.Navy
    $null = Add-Pill -Slide $slide -Text '8-MINUTE LIVE DEMO ROUTE' -Left 55 -Top 40 -Width 210 -Height 30 -Fill $colors.Purple -Color $colors.White -Size 9.5
    $null = Add-Text -Slide $slide -Text 'Show the closed loop, not every menu item' -Left 51 -Top 87 -Width 710 -Height 48 -Size 27 -Color $colors.White -Bold $true -VerticalAlign 1
    $demo = @(
        @{ Code='1'; Title='Products'; Note='Show catalog structure and SKU ownership' },
        @{ Code='2'; Title='Inventory'; Note='Show balance, incoming stock and average cost' },
        @{ Code='3'; Title='Stock movements'; Note='Prove the append-only audit trail' },
        @{ Code='4'; Title='Forecasts'; Note='Show source, range, confidence and algorithm' },
        @{ Code='5'; Title='Recommendations'; Note='Show risks and Accept / Modify / Reject' },
        @{ Code='6'; Title='Supplier or promotion insight'; Note='Close with a management signal' }
    )
    for ($i=0; $i -lt $demo.Count; $i++) {
        $row = [Math]::Floor($i / 3)
        $col = $i % 3
        $x = 55 + ($col * 292)
        $y = 163 + ($row * 126)
        $null = Add-Box -Slide $slide -Left $x -Top $y -Width 264 -Height 101 -Fill $colors.White -Line $(if ($i -eq 4) { $colors.Green } else { $colors.Purple }) -Radius 14
        $null = Add-Badge -Slide $slide -Text $demo[$i].Code -Left ($x+15) -Top ($y+16) -Diameter 32 -Fill $(if ($i -eq 4) { $colors.Green } else { $colors.Purple }) -Size 10
        $null = Add-Text -Slide $slide -Text $demo[$i].Title -Left ($x+57) -Top ($y+12) -Width 190 -Height 30 -Size 12.5 -Color $colors.Navy -Bold $true
        $null = Add-Text -Slide $slide -Text $demo[$i].Note -Left ($x+18) -Top ($y+54) -Width 228 -Height 34 -Size 8.7 -Color $colors.Muted -VerticalAlign 1
    }
    $null = Add-Text -Slide $slide -Text 'The value is the closed loop:' -Left 55 -Top 430 -Width 320 -Height 28 -Size 15 -Color $colors.TealSoft -Bold $true
    $null = Add-Text -Slide $slide -Text 'transactions become trusted history -> history becomes forecasts -> forecasts become controlled action.' -Left 55 -Top 463 -Width 825 -Height 42 -Size 15 -Color $colors.White -Bold $true -VerticalAlign 1
    Add-Footer -Slide $slide -Number 22 -Dark $true
    Add-Notes -Slide $slide -Text "If time is limited, follow this six-stop demo. Avoid the empty dashboard. Start with Products and Inventory, prove the audit trail in Stock Movements, show a forecast with its source and algorithm, then show the recommendation decision workflow. Finish with one supplier or promotion insight. The closing sentence is the core message of the entire presentation."

    # Save editable deck and PDF handout.
    $presentation.SaveAs($powerPointPath, 24)
    $presentation.SaveAs($pdfPath, 32)

    # Export all slide previews for QA and selected infographics for reuse.
    foreach ($currentSlide in @($presentation.Slides)) {
        $previewPath = Join-Path $slidePreviewDirectory ('slide-{0:D2}.png' -f $currentSlide.SlideIndex)
        $currentSlide.Export($previewPath, 'PNG', 1600, 900)
    }

    $infographicSlides = @{
        3 = '01-full-system-overview-current.png'
        4 = '02-business-operating-flow-current.png'
        8 = '03-stock-control-process.png'
        11 = '04-forecast-process-current.png'
        13 = '05-recommendation-process.png'
        16 = '06-automation-timeline.png'
        17 = '07-technical-architecture-current.png'
        20 = '08-production-readiness-current.png'
        21 = '09-management-operating-rhythm.png'
    }
    foreach ($entry in $infographicSlides.GetEnumerator()) {
        $exportPath = Join-Path $imageDirectory $entry.Value
        $presentation.Slides.Item([int]$entry.Key).Export($exportPath, 'PNG', 1600, 900)
    }

    Write-Output "Created: $powerPointPath"
    Write-Output "Created: $pdfPath"
    Write-Output "Slides: $($presentation.Slides.Count)"
    Write-Output "Infographics: $($infographicSlides.Count)"
} finally {
    if ($presentation) {
        $presentation.Close()
    }
    if ($powerPoint) {
        $powerPoint.Quit()
    }

    if ($presentation) {
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($presentation)
    }
    if ($powerPoint) {
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($powerPoint)
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
