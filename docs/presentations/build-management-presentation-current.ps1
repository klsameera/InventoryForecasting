$ErrorActionPreference = 'Stop'

$presentationDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$imageDirectory = Join-Path $presentationDirectory 'images'
$previewDirectory = Join-Path $presentationDirectory 'slide-previews'
$pptxPath = Join-Path $presentationDirectory 'inventory-forecasting-management-presentation.pptx'
$pdfPath = Join-Path $presentationDirectory 'inventory-forecasting-management-presentation.pdf'

New-Item -ItemType Directory -Path $imageDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $previewDirectory -Force | Out-Null

function Rgb([string] $hex) {
    $value = $hex.TrimStart('#')
    $red = [Convert]::ToInt32($value.Substring(0, 2), 16)
    $green = [Convert]::ToInt32($value.Substring(2, 2), 16)
    $blue = [Convert]::ToInt32($value.Substring(4, 2), 16)
    return $red + ($green * 256) + ($blue * 65536)
}

$c = @{
    Navy = Rgb '#0B1536'; Ink = Rgb '#182230'; Slate = Rgb '#344054'
    Muted = Rgb '#667085'; Pale = Rgb '#98A2B3'; Border = Rgb '#D8E1EE'
    Canvas = Rgb '#F4F7FB'; White = Rgb '#FFFFFF'
    Purple = Rgb '#6D3EF2'; PurpleSoft = Rgb '#EEE9FF'
    Blue = Rgb '#1769E0'; BlueSoft = Rgb '#E8F1FF'
    Teal = Rgb '#078A99'; TealSoft = Rgb '#E4F7F8'
    Green = Rgb '#0E9F6E'; GreenSoft = Rgb '#E1F7EF'
    Amber = Rgb '#E58A00'; AmberSoft = Rgb '#FFF2D9'
    Red = Rgb '#E5484D'; RedSoft = Rgb '#FDE7E7'
}

$font = 'Segoe UI'
$slideWidth = 960.0
$slideHeight = 540.0

function TextBox {
    param($Slide, [string] $Text, [double] $X, [double] $Y, [double] $W,
        [double] $H, [double] $Size = 16, [int] $Color = $c.Ink,
        [bool] $Bold = $false, [int] $Align = 1, [int] $VAlign = 3)

    $shape = $Slide.Shapes.AddTextbox(1, $X, $Y, $W, $H)
    $shape.TextFrame.AutoSize = 0
    $shape.TextFrame.WordWrap = -1
    $shape.TextFrame.MarginLeft = 4
    $shape.TextFrame.MarginRight = 4
    $shape.TextFrame.MarginTop = 2
    $shape.TextFrame.MarginBottom = 2
    $shape.TextFrame.VerticalAnchor = $VAlign
    $shape.TextFrame.TextRange.Text = $Text
    $shape.TextFrame.TextRange.Font.Name = $font
    $shape.TextFrame.TextRange.Font.Size = $Size
    $shape.TextFrame.TextRange.Font.Bold = $(if ($Bold) { -1 } else { 0 })
    $shape.TextFrame.TextRange.Font.Color.RGB = $Color
    $shape.TextFrame.TextRange.ParagraphFormat.Alignment = $Align
    return $shape
}

function Box {
    param($Slide, [double] $X, [double] $Y, [double] $W, [double] $H,
        [int] $Fill = $c.White, [int] $Line = $c.Border,
        [bool] $Rounded = $true, [bool] $Shadow = $false,
        [double] $Transparency = 0)

    $shape = $Slide.Shapes.AddShape($(if ($Rounded) { 5 } else { 1 }), $X, $Y, $W, $H)
    $shape.Fill.Solid()
    $shape.Fill.ForeColor.RGB = $Fill
    $shape.Fill.Transparency = $Transparency
    $shape.Line.ForeColor.RGB = $Line
    $shape.Line.Weight = 1
    if ($Shadow) {
        try {
            $shape.Shadow.Visible = -1
            $shape.Shadow.ForeColor.RGB = $c.Navy
            $shape.Shadow.Transparency = 0.84
            $shape.Shadow.OffsetY = 3
            $shape.Shadow.Blur = 6
        } catch { }
    }
    return $shape
}

function Line {
    param($Slide, [double] $X1, [double] $Y1, [double] $X2, [double] $Y2,
        [int] $Color = $c.Blue, [double] $Weight = 2,
        [bool] $Arrow = $true, [bool] $Dashed = $false)

    $shape = $Slide.Shapes.AddLine($X1, $Y1, $X2, $Y2)
    $shape.Line.ForeColor.RGB = $Color
    $shape.Line.Weight = $Weight
    if ($Arrow) { $shape.Line.EndArrowheadStyle = 3 }
    if ($Dashed) { $shape.Line.DashStyle = 4 }
    return $shape
}

function Pill {
    param($Slide, [string] $Text, [double] $X, [double] $Y, [double] $W,
        [double] $H, [int] $Fill, [int] $Color, [double] $Size = 9.5)

    $null = Box $Slide $X $Y $W $H $Fill $Fill $true
    $null = TextBox $Slide $Text $X $Y $W $H $Size $Color $true 2
}

function Badge {
    param($Slide, [string] $Text, [double] $X, [double] $Y,
        [int] $Fill, [double] $Diameter = 34, [double] $Size = 10)

    $shape = $Slide.Shapes.AddShape(9, $X, $Y, $Diameter, $Diameter)
    $shape.Fill.Solid()
    $shape.Fill.ForeColor.RGB = $Fill
    $shape.Line.Visible = 0
    $null = TextBox $Slide $Text $X $Y $Diameter $Diameter $Size $c.White $true 2
}

function Footer {
    param($Slide, [int] $Number, [bool] $Dark = $false)
    $color = $(if ($Dark) { $c.Pale } else { $c.Muted })
    $null = TextBox $Slide 'Inventory Forecasting | Current-system briefing | 04 Sep 2026' 48 514 620 16 8 $color $false 1 1
    $null = TextBox $Slide ([string] $Number) 840 512 72 18 8 $color $true 3 1
}

function Notes {
    param($Slide, [string] $Text)
    try {
        $placeholders = $Slide.NotesPage.Shapes.Placeholders
        if ($placeholders.Count -ge 2) {
            $placeholders.Item(2).TextFrame.TextRange.Text = $Text
        }
    } catch { }
}

function New-Slide {
    param($Presentation, [string] $Title, [string] $Kicker, [string] $Subtitle = '')
    $slide = $Presentation.Slides.Add($Presentation.Slides.Count + 1, 12)
    $slide.FollowMasterBackground = 0
    $slide.Background.Fill.Solid()
    $slide.Background.Fill.ForeColor.RGB = $c.Canvas
    $null = TextBox $slide $Kicker 48 28 700 18 9 $c.Purple $true 1 1
    $null = TextBox $slide $Title 44 47 868 42 25 $c.Navy $true 1 1
    if ($Subtitle) { $null = TextBox $slide $Subtitle 48 88 850 34 11.5 $c.Muted $false 1 1 }
    return $slide
}

function Panel {
    param($Slide, [string] $Title, [string] $Body, [double] $X, [double] $Y,
        [double] $W, [double] $H, [int] $Color = $c.Blue,
        [int] $Fill = $c.White, [double] $BodySize = 10.5)

    $null = Box $Slide $X $Y $W $H $Fill $Color $true $true
    $null = Box $Slide $X $Y $W 8 $Color $Color $true
    $null = TextBox $Slide $Title ($X + 18) ($Y + 20) ($W - 36) 34 14 $c.Navy $true 1 1
    $null = TextBox $Slide $Body ($X + 18) ($Y + 61) ($W - 36) ($H - 75) $BodySize $c.Slate $false 1 1
}

function Stat {
    param($Slide, [string] $Label, [string] $Value, [string] $Detail,
        [double] $X, [double] $Y, [double] $W, [double] $H,
        [int] $Color = $c.Blue, [double] $ValueSize = 19)

    $null = Box $Slide $X $Y $W $H $c.White $c.Border $true $true
    $null = Box $Slide $X $Y 7 $H $Color $Color $true
    $null = TextBox $Slide $Label ($X + 18) ($Y + 10) ($W - 32) 20 8.7 $c.Muted $true 1 1
    $null = TextBox $Slide $Value ($X + 18) ($Y + 34) ($W - 32) 34 $ValueSize $c.Navy $true 1 1
    if ($Detail) { $null = TextBox $Slide $Detail ($X + 18) ($Y + 72) ($W - 32) ($H - 80) 8.4 $c.Muted $false 1 1 }
}

function FlowCard {
    param($Slide, [string] $Code, [string] $Title, [string] $Body,
        [double] $X, [double] $Y, [double] $W, [double] $H, [int] $Color)

    $null = Box $Slide $X $Y $W $H $c.White $Color $true $true
    Badge $Slide $Code ($X + (($W - 34) / 2)) ($Y + 16) $Color 34 9
    $null = TextBox $Slide $Title ($X + 8) ($Y + 57) ($W - 16) 31 11 $Color $true 2 1
    $null = TextBox $Slide $Body ($X + 12) ($Y + 96) ($W - 24) ($H - 108) 9 $c.Slate $false 2 1
}

function MetricBar {
    param($Slide, [string] $Label, [double] $Value, [double] $Maximum,
        [string] $Display, [double] $Y, [int] $Color)

    $null = TextBox $Slide $Label 69 ($Y - 2) 115 22 9.5 $c.Slate $true 3
    $null = Box $Slide 194 $Y 292 18 $c.Canvas $c.Canvas $true
    $barWidth = [Math]::Max(8, (($Value / $Maximum) * 292))
    $null = Box $Slide 194 $Y $barWidth 18 $Color $Color $true
    $null = TextBox $Slide $Display 495 ($Y - 2) 55 22 9.5 $c.Navy $true 3
}

$powerPoint = $null
$presentation = $null

try {
    $powerPoint = New-Object -ComObject PowerPoint.Application
    $powerPoint.Visible = -1
    $presentation = $powerPoint.Presentations.Add()
    $presentation.PageSetup.SlideWidth = $slideWidth
    $presentation.PageSetup.SlideHeight = $slideHeight

    # 1. Title
    $slide = $presentation.Slides.Add(1, 12)
    $slide.FollowMasterBackground = 0
    $slide.Background.Fill.Solid()
    $slide.Background.Fill.ForeColor.RGB = $c.Navy
    $accent = Box $slide 690 -80 360 265 $c.Purple $c.Purple $true $false 0.12
    $accent.Rotation = 18
    $accent = Box $slide 725 325 310 195 $c.Teal $c.Teal $true $false 0.18
    $accent.Rotation = -11
    Pill $slide 'MANAGEMENT & TEAM BRIEFING' 58 48 238 30 $c.Purple $c.White 10
    $null = TextBox $slide 'Inventory Forecasting' 54 108 630 62 38 $c.White $true 1 1
    $null = TextBox $slide 'Read-only demand prediction powered by BuyAbans data' 58 178 590 52 20 $c.White $false 1 1
    $null = Line $slide 60 264 818 264 $c.Teal 3 $false
    $titleSteps = @(
        @{Code='01'; Title='CONNECT'; Body='Authenticated BuyAbans API'},
        @{Code='02'; Title='CURATE'; Body='Sync, normalize and reconcile'},
        @{Code='03'; Title='PREDICT'; Body='Dense series + four algorithms'},
        @{Code='04'; Title='DECIDE'; Body='Explainable human review'}
    )
    for ($i=0; $i -lt $titleSteps.Count; $i++) {
        $x = 60 + ($i * 205)
        Badge $slide $titleSteps[$i].Code $x 294 $(if ($i -eq 3) { $c.Green } else { $c.Purple }) 34 9
        $null = TextBox $slide $titleSteps[$i].Title ($x + 43) 294 145 22 11 $c.White $true 1 1
        $null = TextBox $slide $titleSteps[$i].Body ($x + 43) 319 150 38 9.5 $c.Pale $false 1 1
    }
    Pill $slide 'Read-only business data' 58 417 184 32 $c.Blue $c.White
    Pill $slide 'All 3 demand grains synced' 254 417 204 32 $c.Teal $c.White
    Pill $slide 'EWMA remains the default' 470 417 220 32 $c.Green $c.White
    $null = TextBox $slide '04 September 2026' 60 492 240 20 9 $c.Pale $false 1 1
    Notes $slide "Open with the scope correction: this is now a prediction layer, not a stock-management replacement. BuyAbans remains the system of record. The app pulls data read-only, explains performance, forecasts demand and records controlled decisions."

    # 2. Executive summary
    $slide = New-Slide $presentation 'The system now has a clear job' 'EXECUTIVE SUMMARY' 'Convert back-office data into management visibility and demand forecasts without creating a second operational truth.'
    $cards = @(
        @{X=48; N='1'; Title='READ'; Body="Catalog, current stock and order history arrive through authenticated GET endpoints."; Color=$c.Blue},
        @{X=270; N='2'; Title='TRUST'; Body="Sync is idempotent; variants and attributes are normalized; demand grains reconcile."; Color=$c.Teal},
        @{X=492; N='3'; Title='PREDICT'; Body="Daily series are densified, forecasted with four algorithms and shown with uncertainty."; Color=$c.Purple},
        @{X=714; N='4'; Title='GOVERN'; Body="EWMA remains default; decisions stay human; nothing writes back to BuyAbans."; Color=$c.Green}
    )
    foreach ($card in $cards) { FlowCard $slide $card.N $card.Title $card.Body $card.X 142 198 250 $card.Color }
    $null = Box $slide 48 420 864 70 $c.AmberSoft $c.Amber $true
    $null = TextBox $slide 'Management boundary' 66 434 150 26 12 $c.Amber $true
    $null = TextBox $slide 'The forecast path is current. Recommendations and several legacy analytics still need a BuyAbans stock-to-location design before they can be trusted as production signals.' 214 430 675 44 11.2 $c.Navy $true
    Footer $slide 2
    Notes $slide "The division of responsibility is now clear. BuyAbans owns transactions; this application consumes and interprets them. The forecast path is current. The recommendation workflow is implemented, but its present numbers still read the legacy inventory ledger."

    # 3. End-to-end system flow
    $slide = New-Slide $presentation 'End-to-end: source data to human decision' 'PROCESS INFOGRAPHIC' 'A one-way data path protects the back office while creating an analytical and forecasting layer.'
    $flow = @(
        @{X=32; N='1'; T='BUYABANS'; B="Catalog`r`nCurrent stock`r`nOrders / refunds"; C=$c.Blue},
        @{X=183; N='2'; T='API'; B="Passport OAuth`r`n9 read-only GETs`r`nPaged responses"; C=$c.Teal},
        @{X=334; N='3'; T='SYNC'; B="Dependency order`r`nNormalize catalog`r`nReconcile grains"; C=$c.Blue},
        @{X=485; N='4'; T='PREPARE'; B="Sparse raw demand`r`nFill zero days`r`nMaturity / peers"; C=$c.Purple},
        @{X=636; N='5'; T='PREDICT'; B="EWMA / seasonal`r`nTFT / DeepAR`r`nRange + confidence"; C=$c.Purple},
        @{X=787; N='6'; T='REVIEW'; B="Dashboard`r`nForecasts`r`nHuman decisions"; C=$c.Green}
    )
    for ($i=0; $i -lt $flow.Count; $i++) {
        if ($i -lt ($flow.Count - 1)) { $null = Line $slide ($flow[$i].X + 136) 279 ($flow[$i+1].X - 4) 279 $flow[$i].C 2.3 }
        FlowCard $slide $flow[$i].N $flow[$i].T $flow[$i].B $flow[$i].X 151 132 255 $flow[$i].C
    }
    Pill $slide 'ONE-WAY BOUNDARY: no catalog, stock or order write-back' 186 438 590 34 $c.Navy $c.White
    Footer $slide 3
    Notes $slide "Walk left to right. BuyAbans exposes authenticated read-only endpoints. Laravel syncs and normalizes the data, reconstructs the calendar, selects an algorithm and persists explainable output. The arrow never points back to BuyAbans."

    # 4. Responsibility boundary
    $slide = New-Slide $presentation 'One system of record; one prediction layer' 'RESPONSIBILITY BOUNDARY' 'The separation prevents local edits from being overwritten or mistaken for operational truth.'
    Panel $slide 'BUYABANS BACK OFFICE - owns and changes' "Products, variants, categories and attributes`r`nInventory sources and current stock`r`nOrders, cancellations and refunds`r`nOperational corrections and master data" 48 140 386 250 $c.Blue $c.BlueSoft
    Panel $slide 'FORECASTING APP - reads and computes' "Sync history and data-quality evidence`r`nManagement dashboard and forecasting`r`nAccuracy scoring and model selection`r`nRecommendations and intelligence views`r`nUser account security" 526 140 386 250 $c.Purple $c.PurpleSoft
    $null = Line $slide 442 238 516 238 $c.Teal 3
    $null = TextBox $slide 'GET only' 448 204 64 24 10 $c.Teal $true 2
    $null = Line $slide 516 292 442 292 $c.Red 2 $false $true
    $null = TextBox $slide 'NO WRITE-BACK' 438 304 84 22 8.5 $c.Red $true 2
    $null = Box $slide 114 421 732 56 $c.GreenSoft $c.Green $true
    $null = TextBox $slide 'No create, edit or delete route exists for business data. Local writes are operational records: syncs, forecasts, scores, recommendation decisions and account settings.' 132 432 696 34 10.5 $c.Navy $true 2
    Footer $slide 4
    Notes $slide "Business-data write routes, forms and pages were removed, not hidden. Local writes are limited to this application's own operation and audit records. Suppliers and promotions are also read-only, so they need an external source if their data must change."

    # 5. Capability map
    $slide = New-Slide $presentation 'What users can see and do today' 'CURRENT CAPABILITY MAP' 'The sidebar is organized around visibility, forecasting and investigation - not stock operation.'
    $caps = @(
        @{X=48; Y=137; T='OVERVIEW'; B="Dashboard`r`nBuyAbans sync"; C=$c.Blue},
        @{X=342; Y=137; T='CATALOG'; B="Products, variants, SKUs`r`nCategories, brands, attributes"; C=$c.Teal},
        @{X=636; Y=137; T='INVENTORY VIEWS'; B="Current stock, analytics, batches`r`nSnapshots and warehouses"; C=$c.Blue},
        @{X=48; Y=301; T='SUPPLY REFERENCE'; B="Read-only suppliers`r`nHistorical operations remain by URL"; C=$c.Amber},
        @{X=342; Y=301; T='FORECASTING'; B="Forecasts and runs`r`nRecommendations and allocation"; C=$c.Purple},
        @{X=636; Y=301; T='ADVANCED INTELLIGENCE'; B="Supplier, demand, product, price`r`nand promotion signals"; C=$c.Green}
    )
    foreach ($cap in $caps) { Panel $slide $cap.T $cap.B $cap.X $cap.Y 276 132 $cap.C $c.White 10 }
    Pill $slide 'All pages require authentication + verified email; role-based permissions are not implemented.' 138 462 684 32 $c.RedSoft $c.Red 9.3
    Footer $slide 5
    Notes $slide "The user journey begins with the Dashboard or BuyAbans Sync, then moves to read-only catalog and inventory views, forecasts and investigations. Old stock-operation listings remain readable by URL for historical data, but they have no write routes."

    # 6. Sync process
    $slide = New-Slide $presentation 'How a BuyAbans sync becomes usable data' 'PROCESS INFOGRAPHIC' 'Dependencies run in order, re-runs correct existing rows, and failures remain visible in sync history.'
    $sync = @(
        @{N='1'; T='LOCATIONS'; C=$c.Blue}, @{N='2'; T='CATEGORIES'; C=$c.Blue},
        @{N='3'; T='BRANDS'; C=$c.Teal}, @{N='4'; T='ATTRIBUTES'; C=$c.Teal},
        @{N='5'; T='PRODUCTS'; C=$c.Purple}, @{N='6'; T='STOCK'; C=$c.Purple},
        @{N='7'; T='DEMAND'; C=$c.Green}
    )
    for ($i=0; $i -lt $sync.Count; $i++) {
        $x = 42 + ($i * 126)
        if ($i -lt ($sync.Count - 1)) { $null = Line $slide ($x + 104) 218 ($x + 121) 218 $sync[$i].C 2 }
        $null = Box $slide $x 165 104 106 $c.White $sync[$i].C $true $true
        Badge $slide $sync[$i].N ($x + 35) 179 $sync[$i].C
        $null = TextBox $slide $sync[$i].T ($x + 4) 225 96 28 8.2 $c.Navy $true 2
    }
    Panel $slide 'AUTHENTICATED' 'Passport client credentials; token cached until near expiry.' 48 310 276 116 $c.Blue $c.White 9.5
    Panel $slide 'IDEMPOTENT' 'Upserts correct an existing window; a re-run never double-counts demand.' 342 310 276 116 $c.Teal $c.White 9.5
    Panel $slide 'RECOVERABLE' 'A failed stage stops the run, records the reason and keeps completed stages.' 636 310 276 116 $c.Amber $c.White 9.5
    Pill $slide 'Nightly 00:05: replay 14 days so cancellations and refunds update history' 173 449 614 34 $c.Navy $c.White 9.3
    Footer $slide 6
    Notes $slide "Locations and catalog references run before products, stock and demand. The sync is idempotent and deliberately re-pulls fourteen days nightly because past orders can change. Test Connection diagnoses access; Sync Now runs one stage or all stages."

    # 7. Grain reconciliation
    $slide = New-Slide $presentation 'All three demand views reconcile to the unit' 'DATA RECONCILIATION' 'Different grains describe the same sales from different angles; they must never be summed together.'
    Panel $slide 'WAREHOUSE' "942,873 rows`r`n11 locations`r`n535 SKUs`r`n1,826,136 units" 48 146 276 225 $c.Blue $c.BlueSoft 12
    Panel $slide 'CHANNEL' "571,362 rows`r`n4 channels`r`n535 SKUs`r`n1,826,136 units" 342 146 276 225 $c.Teal $c.TealSoft 12
    Panel $slide 'NATIONAL' "348,937 rows`r`n1 total`r`n535 SKUs`r`n1,826,136 units" 636 146 276 225 $c.Purple $c.PurpleSoft 12
    $null = Line $slide 186 392 774 392 $c.Green 3 $false
    Pill $slide 'EXACT MATCH: 1,826,136 units at every grain' 278 374 404 36 $c.Green $c.White 10
    Pill $slide '2022-09-04 to 2026-09-04 | unmatched SKUs: 0 | active grain: warehouse' 153 438 654 34 $c.Navy $c.White 9.2
    Footer $slide 7
    Notes $slide "The unit totals match exactly across warehouse, channel and national grains. This is the key reconciliation control. Serving and training currently use warehouse. Queries must always isolate one grain or they silently double-count the same sales."

    # 8. Catalog normalization
    $slide = New-Slide $presentation 'The sync rebuilds a usable product tree' 'PROCESS INFOGRAPHIC' 'Parentage and common variant axes are normalized during ingestion instead of flattening or duplicating the catalog.'
    Panel $slide 'BACK-OFFICE FEED' "Configurable parents`r`nSimple child variants`r`nStandalone products`r`nPer-product size/color/capacity" 48 145 246 235 $c.Blue $c.BlueSoft 10.7
    $null = Line $slide 298 262 361 262 $c.Teal 3
    Panel $slide 'THREE-PASS NORMALIZATION' "1. Parents + standalone`r`n2. Variants linked to parents`r`n3. Orphan cleanup`r`n`r`n475 source attributes -> 52 local" 365 145 230 235 $c.Teal $c.TealSoft 10.1
    $null = Line $slide 599 262 662 262 $c.Purple 3
    Panel $slide 'LOCAL FORECAST CATALOG' "363 configurable parents`r`n1,857 linked variants`r`n9,035 standalone products`r`n10,892 SKUs`r`n1,929 normalized axis values" 666 145 246 235 $c.Purple $c.PurpleSoft 10.5
    Pill $slide '3 canonical forecast axes: Color | Size | Capacity' 89 416 360 34 $c.GreenSoft $c.Green 9.2
    Pill $slide 'Visible source issues: 151 duplicate SKU claims | 1 childless parent' 469 416 402 34 $c.AmberSoft $c.Amber 8.8
    Footer $slide 8
    Notes $slide "The original sync flattened the variant catalog. The current three-pass sync reconstructs parents and children, then removes orphans. It also collapses hundreds of product-specific size, color and capacity definitions into common axes. Duplicate SKU claims are reported rather than silently stealing ownership."

    # 9. Sparse data correction
    $slide = New-Slide $presentation 'A missing row means zero sales - not a missing day' 'DATA-QUALITY PROCESS' 'The source feed stays sparse; every forecasting consumer reconstructs the calendar before modelling.'
    Panel $slide 'SPARSE API FEED' "A row only when a sale occurred`r`nNo row on a quiet day`r`n`r`nFaithful raw integration record" 48 148 245 215 $c.Red $c.RedSoft 10.3
    $null = Line $slide 300 255 355 255 $c.Teal 3
    Panel $slide 'FILL THE CALENDAR' "Insert zero-demand days`r`nAlign covariates day-for-day`r`nStop at last synced day`r`nNever pad unknown future days" 361 148 238 215 $c.Teal $c.TealSoft 10.3
    $null = Line $slide 606 255 661 255 $c.Green 3
    Panel $slide 'DENSE SERIES' "941,116 training rows`r`nMedian 1,096 days per series`r`n51% non-zero`r`nZero calendar gaps" 667 148 245 215 $c.Green $c.GreenSoft 10.3
    $null = TextBox $slide 'Before correction' 96 394 190 24 9.5 $c.Slate $true
    $null = Box $slide 286 397 392 18 $c.Red $c.Red $true
    $null = TextBox $slide '51,919 forecast' 690 393 128 24 10 $c.Red $true
    $null = TextBox $slide 'After correction' 96 431 190 24 9.5 $c.Slate $true
    $null = Box $slide 286 434 211 18 $c.Green $c.Green $true
    $null = TextBox $slide '27,952 vs 27,132 actual - within 3%' 510 428 300 30 9.5 $c.Green $true
    Footer $slide 9
    Notes $slide "Reading only selling days as consecutive time overstated the forecast by 91 percent. Reconstructing the calendar reduced 51,919 forecast units to 27,952 against 27,132 actual. The raw table stays sparse; zero days are inserted at the modelling boundary."

    # 10. Dashboard
    $slide = New-Slide $presentation 'The dashboard is now a management briefing' 'CURRENT USER EXPERIENCE' 'Every window ends on the last synced demand date, so stale data never masquerades as a run of zero sales.'
    $tiles = @(
        @{X=48; Y=143; L='Revenue'; V='LKR'; C=$c.Blue}, @{X=260; Y=143; L='Units sold'; V='30 days'; C=$c.Teal},
        @{X=472; Y=143; L='Orders'; V='30 days'; C=$c.Purple}, @{X=684; Y=143; L='Average order value'; V='LKR'; C=$c.Green},
        @{X=48; Y=239; L='Stock on hand'; V='retail'; C=$c.Blue}, @{X=260; Y=239; L='Days of cover'; V='demand-led'; C=$c.Teal},
        @{X=472; Y=239; L='Lines out of stock'; V='exceptions'; C=$c.Red}, @{X=684; Y=239; L='Reorder alerts'; V='pending'; C=$c.Amber}
    )
    foreach ($tile in $tiles) { Stat $slide $tile.L $tile.V '' $tile.X $tile.Y 180 78 $tile.C 16 }
    Panel $slide 'TRADE' "Units + revenue per week`r`nRevenue by category / warehouse`r`nTop movers by units" 48 340 276 125 $c.Blue $c.White 9.5
    Panel $slide 'STOCK HEALTH' "Fixed cover bands`r`nOut-of-stock exceptions`r`nRetail value, clearly labelled" 342 340 276 125 $c.Teal $c.White 9.5
    Panel $slide 'FORECAST HEALTH' "Latest run and status`r`nPredictions + horizon`r`nAccuracy stays blank until scored" 636 340 276 125 $c.Purple $c.White 9.5
    Footer $slide 10
    Notes $slide "The dashboard now covers trade, money, stock exposure and forecast health. Revenue and units use separate charts. Stock value is retail because cost is populated for only 149 of 10,892 SKUs. Accuracy remains absent until honestly scored. Dashboard response time was reduced from 70.4 seconds to about 2.5 seconds."

    # 11. Forecast UX
    $slide = New-Slide $presentation 'The forecast page answers: what happens next?' 'CURRENT USER EXPERIENCE' 'Plain language, a comparison window, a likely range and visible confidence replace technical codes.'
    Stat $slide 'EXPECTED UNITS' '31,365' '+3% versus the preceding 30 days' 48 140 198 118 $c.Purple
    Stat $slide 'PREVIOUS WINDOW' '30,445' 'Observed sales in the same-length window' 270 140 198 118 $c.Blue
    Stat $slide 'LIKELY RANGE' '19,314-44,287' 'Use the range when confidence is low' 492 140 198 118 $c.Teal 16
    Stat $slide 'COVERAGE' '533 products' 'Overall confidence: Low' 714 140 198 118 $c.Amber 17
    $null = Box $slide 48 290 552 174 $c.White $c.Border $true $true
    $null = TextBox $slide 'SALES SO FAR, AND WHAT COMES NEXT' 66 306 350 22 9 $c.Muted $true
    $points = @(@(79,421,162,385), @(162,385,245,401), @(245,401,328,366), @(328,366,411,388))
    foreach ($point in $points) { $null = Line $slide $point[0] $point[1] $point[2] $point[3] $c.Blue 2.5 $false }
    $null = Line $slide 411 388 552 375 $c.Purple 3 $false $true
    $null = TextBox $slide 'Actual weekly sales' 82 438 150 18 8.5 $c.Blue $true
    $null = TextBox $slide 'Expected weekly average + range' 340 438 230 18 8.5 $c.Purple $true 3
    Panel $slide 'THE TABLE SPEAKS BUSINESS' "Expected to sell`r`nOver the next N days`r`nHow sure`r`nBased on`r`nHow it turned out`r`n`r`nLatest run by default" 624 290 288 174 $c.Green $c.White 9.2
    Pill $slide 'Generated-data evidence - not production demand' 322 476 316 26 $c.AmberSoft $c.Amber 8.5
    Footer $slide 11
    Notes $slide "The latest logged page showed 31,365 expected units versus 30,445 previously, with a 19,314 to 44,287 range, low confidence and 533 products. The forecast line is a weekly average because the model produces one total, not a week-by-week curve."

    # 12. Forecast process
    $slide = New-Slide $presentation 'How one forecast is produced and governed' 'PROCESS INFOGRAPHIC' 'Selection happens before inference; scoring happens only after the predicted horizon has finished.'
    $forecast = @(
        @{X=34; N='1'; T='GRAIN'; B="Configured source`r`nWarehouse today"; C=$c.Blue},
        @{X=184; N='2'; T='DENSE SERIES'; B="180 daily values`r`nZero days restored"; C=$c.Teal},
        @{X=334; N='3'; T='MATURITY'; B="Own history or`r`npeer fallback"; C=$c.Blue},
        @{X=484; N='4'; T='SELECT'; B="Scored winner or`r`nconfigured default"; C=$c.Purple},
        @{X=634; N='5'; T='INFER'; B="EWMA / seasonal`r`nTFT / DeepAR"; C=$c.Purple},
        @{X=784; N='6'; T='EXPLAIN'; B="Quantity, range`r`nconfidence, source"; C=$c.Green}
    )
    for ($i=0; $i -lt $forecast.Count; $i++) {
        if ($i -lt ($forecast.Count - 1)) { $null = Line $slide ($forecast[$i].X + 132) 268 ($forecast[$i+1].X - 3) 268 $forecast[$i].C 2.2 }
        FlowCard $slide $forecast[$i].N $forecast[$i].T $forecast[$i].B $forecast[$i].X 153 132 230 $forecast[$i].C
    }
    $null = Line $slide 850 400 110 400 $c.Amber 2 $true $true
    Pill $slide 'After horizon: actuals -> WAPE / MAE / bias -> next model selection' 179 425 602 36 $c.AmberSoft $c.Amber 9.3
    Pill $slide 'Neural refusal is recorded as the baseline that actually ran' 264 471 432 28 $c.GreenSoft $c.Green 8.7
    Footer $slide 12
    Notes $slide "Maturity determines whether the SKU uses its own history or peers. The selector uses a scored winner only with enough comparable evidence; otherwise configuration applies. Python echoes what actually ran. Accuracy is scored later and is distinct from confidence."

    # 13. Model governance
    $slide = New-Slide $presentation 'EWMA remains the evidence-based default' 'MODEL GOVERNANCE' 'Lower WAPE is better. Saved evaluations are from generated BuyAbans history and prove the pipeline, not real-world accuracy.'
    Panel $slide 'LATEST SAVED 30-DAY CROSS-MODEL EVALUATION' '' 48 138 525 312 $c.Blue $c.White
    MetricBar $slide 'EWMA' 19.59 120 '19.6%' 207 $c.Green
    MetricBar $slide 'Seasonal naive' 27.63 120 '27.6%' 257 $c.Teal
    MetricBar $slide 'DeepAR' 29.17 120 '29.2%' 307 $c.Purple
    MetricBar $slide 'TFT' 111.94 120 '111.9%' 357 $c.Red
    $null = TextBox $slide 'Actual: 26,592 units | EWMA predicted: 28,753' 74 406 458 24 9 $c.Muted $true 2
    Panel $slide 'NEURAL TRAINING ARTIFACTS' "365-day holdout`r`nTFT WAPE 98.3% | bias -2.4%`r`nDeepAR WAPE 102.0% | bias +12.6%`r`n`r`nCheckpoints + dataset parameters regenerated on 04 Sep.`r`n`r`nDo not switch without real scored history." 600 138 312 312 $c.Purple $c.PurpleSoft 10.2
    Pill $slide 'CONFIGURED DEFAULT: EWMA' 357 469 246 31 $c.Green $c.White 9.5
    Footer $slide 13
    Notes $slide "The latest saved cross-model evaluation names EWMA as best at 19.6 percent WAPE. Final neural artifacts use a 365-day holdout, but those generated-data results do not justify switching. No figure here is a production accuracy promise."

    # 14. Performance and scale
    $slide = New-Slide $presentation 'The widened dataset is usable at operating scale' 'PERFORMANCE & SCALE' 'Indexes, date-range corrections and worker limits were measured against the current four-year dataset.'
    Stat $slide 'SYNCED DEMAND' '1.826M units' 'Each grain reconciles to the same total' 48 145 198 126 $c.Blue
    Stat $slide 'FORECAST RUN' '2,300 rows' 'Completed in 2m22s across 2,259 request pairs' 270 145 198 126 $c.Purple
    Stat $slide 'DASHBOARD' '2.5s' 'Reduced from 70.4s' 492 145 198 126 $c.Teal
    Stat $slide 'FORECAST PAGE' '0.9s' 'Reduced from 19.5s' 714 145 198 126 $c.Green
    Panel $slide 'WHY THE INDEXES MATTER' "Back office: orders (created_at, status)`r`nApp: daily demands (grain, sku_id)`r`n`r`nDistinct-SKU query: 8.92s -> 0.44s" 48 306 410 151 $c.Blue $c.White 10
    Panel $slide 'WHY THE WORKER LIMIT MATTERS' "A 60-second listener silently killed a full run and left it at Processing.`r`n`r`nJob + listener timeout: 1,800 seconds. Production needs stale-run alerts." 502 306 410 151 $c.Amber $c.AmberSoft 10
    Footer $slide 14
    Notes $slide "Two indexes and date-range corrections made the wider data usable. The forecast run also outgrew the default queue listener timeout. Both job and local listener now allow 1,800 seconds; production still needs monitoring for stuck runs."

    # 15. Recommendation gap
    $slide = New-Slide $presentation 'Recommendation workflow exists; its stock input is not current' 'CRITICAL DATA GAP' 'Forecasts and current BuyAbans stock do not yet meet at a compatible location grain.'
    Panel $slide 'CURRENT FORECAST POPULATION' "2,300 fresh forecasts`r`nBuyAbans warehouse demand`r`n535 SKUs across locations" 48 153 250 190 $c.Green $c.GreenSoft 11
    $null = Line $slide 302 248 358 248 $c.Red 3
    Panel $slide 'MAPPING DECISION NEEDED' "BuyAbans stock is per inventory source, not warehouse.`r`n`r`nThere is no honest mechanical mapping." 364 153 232 190 $c.Red $c.RedSoft 10.5
    $null = Line $slide 600 248 656 248 $c.Red 3
    Panel $slide 'LEGACY ENGINE INPUT' "inventories: 441 rows`r`n149 SKUs | 6 warehouses`r`nOnly 4 forecast pairs overlap`r`n197 existing recommendations" 662 153 250 190 $c.Amber $c.AmberSoft 10.7
    $null = Box $slide 48 382 864 76 $c.Navy $c.Navy $true
    $null = TextBox $slide 'Safe claim today' 68 395 150 24 12 $c.TealSoft $true
    $null = TextBox $slide 'Accept / Modify / Reject is implemented and auditable. Present quantities and the reorder-alert count are legacy-data demonstrations. Nothing auto-creates a purchase order or transfer.' 218 390 670 54 10.5 $c.White $true
    Footer $slide 15
    Notes $slide "The engine iterates the old inventories table: 441 rows across 149 SKUs and six warehouses. Only four of 2,300 current forecast pairs overlap. Management must decide a stock-source mapping or redesign the recommendation grain before these become live decisions."

    # 16. Data lineage map
    $slide = New-Slide $presentation 'Not every screen is on the same freshness path yet' 'DATA-LINEAGE MAP' 'Production trust depends on knowing exactly which source each view reads.'
    Panel $slide 'CURRENT BUYABANS PATH' "Dashboard trading metrics`r`nRevenue/category/location charts`r`nForecast training and serving`r`nMaturity and peer profiles`r`nCatalog, variants, attributes and stock" 48 146 386 250 $c.Green $c.GreenSoft 11
    Panel $slide 'LEGACY OR FROZEN INPUT PATH' "Inventory analytics and recommendations`r`nLost sales and anomalies`r`nSupplier performance`r`nProduct relationships / elasticity`r`nPromotion impact`r`n`r`nFunctional, but not replenished by this sync." 526 146 386 250 $c.Amber $c.AmberSoft 10.5
    Pill $slide 'Demonstrate capability, state lineage, never imply all figures share the live sync.' 126 430 708 38 $c.Navy $c.White 9.5
    Footer $slide 16
    Notes $slide "Advanced features remain implemented, but several read snapshots, movements, purchase records or sales records the BuyAbans sync does not populate. Present them as delivered capabilities and a migration roadmap, not current BuyAbans analysis."

    # 17. Automation
    $slide = New-Slide $presentation 'The daily and monthly operating cycle' 'PROCESS INFOGRAPHIC' 'Sequence protects dependency: synchronize first, then capture, score and recommend.'
    $timeline = @(
        @{X=48; Time='00:05'; T='SYNC'; B='Latest 14 days'; C=$c.Blue},
        @{X=220; Time='00:15'; T='SNAPSHOT'; B='Previous day'; C=$c.Teal},
        @{X=392; Time='00:30'; T='SCORE'; B='Due forecasts'; C=$c.Purple},
        @{X=564; Time='00:45'; T='RECOMMEND'; B='Refresh queue'; C=$c.Green},
        @{X=736; Time='MONTHLY'; T='LEARN'; B="Supplier 01:00`r`nModels 02:00"; C=$c.Amber}
    )
    $null = Line $slide 86 259 852 259 $c.Border 4 $false
    foreach ($item in $timeline) {
        Badge $slide $item.Time ($item.X + 43) 224 $item.C 70 $(if ($item.Time -eq 'MONTHLY') { 7.5 } else { 10 })
        $null = TextBox $slide $item.T $item.X 309 156 26 10 $item.C $true 2
        $null = TextBox $slide $item.B $item.X 339 156 42 9.2 $c.Muted $false 2 1
    }
    $null = Box $slide 48 410 864 64 $c.AmberSoft $c.Amber $true
    $null = TextBox $slide 'Operating requirement' 66 425 150 25 11 $c.Amber $true
    $null = TextBox $slide 'Host cron + supervised queue + supervised Python + alerts. Snapshot/recommendation jobs still follow the legacy inventory path until stock mapping is resolved.' 211 418 680 39 10.2 $c.Navy $true
    Footer $slide 17
    Notes $slide "The sync runs at 00:05, snapshots at 00:15, scoring at 00:30 and recommendations at 00:45. Supplier capture and neural training run monthly. Cron, queue and Python supervision are production requirements."

    # 18. Architecture
    $slide = New-Slide $presentation 'Two applications, two runtimes, one analytical experience' 'TECHNICAL ARCHITECTURE' 'Laravel owns orchestration; Python provides inference; BuyAbans remains external truth.'
    Panel $slide 'BROWSER' "React 19 + Inertia v3`r`nBootstrap 5.3 + SCSS`r`nDashboard, sync, forecasts, decisions" 48 149 210 188 $c.Blue $c.BlueSoft 10
    $null = Line $slide 262 243 329 243 $c.Blue 3
    Panel $slide 'LARAVEL 13' "Authentication + verified access`r`nController -> Facade -> Service`r`nSync, queue and scheduler`r`nRead-only business routes" 335 134 290 218 $c.Purple $c.PurpleSoft 10.4
    $null = Line $slide 629 202 696 174 $c.Teal 3
    $null = Line $slide 629 283 696 311 $c.Purple 3
    Panel $slide 'BUYABANS' "Laravel 10 / Bagisto`r`nPassport client credentials`r`n9 read-only endpoints" 702 120 210 150 $c.Teal $c.TealSoft 9.8
    Panel $slide 'PYTHON ML' "FastAPI on :8090`r`nEWMA, seasonal naive`r`nTFT and DeepAR" 702 292 210 150 $c.Purple $c.PurpleSoft 10
    Panel $slide 'MYSQL + DATABASE QUEUE' "Synced catalog / demand / stock`r`nForecasts, scores, runs and decisions" 335 389 290 90 $c.Navy $c.White 9.2
    $null = Line $slide 480 352 480 386 $c.Navy 2.5
    Footer $slide 18
    Notes $slide "Laravel authenticates users, orchestrates the sync, prepares series and owns persistence. BuyAbans is a separate OAuth-protected application. Python serves the algorithms. Production needs private networking, credentials and monitoring for both connections."

    # 19. Controls
    $slide = New-Slide $presentation 'Strong boundaries are delivered; authorization is not' 'SECURITY & CONTROL' 'Authentication, data integrity and model honesty exist today; production access governance still needs ownership.'
    Panel $slide 'ACCOUNT CONTROLS' "Email verification`r`nPassword reset + confirmation`r`nTOTP two-factor authentication`r`nPasskeys`r`nLogin/challenge throttling" 48 142 276 260 $c.Blue $c.White 10.5
    Panel $slide 'DATA CONTROLS' "Read-only business routes`r`nOAuth machine identity`r`nIdempotent sync + run history`r`nGrain isolation / reconciliation`r`nNamed neural fallback`r`nHuman decision record" 342 142 276 260 $c.Green $c.White 10.2
    Panel $slide 'REQUIRED FOR PRODUCTION' "Role-based permissions`r`nControlled registration`r`nReal mail + cookie policy`r`nSecret rotation / token protection`r`nBackups and monitoring`r`nRelease validation" 636 142 276 260 $c.Red $c.RedSoft 10.2
    Pill $slide 'Today: every authenticated, verified user has broad visibility and operational triggers.' 160 439 640 34 $c.AmberSoft $c.Amber 9.4
    Footer $slide 19
    Notes $slide "Authentication is strong and business data is read-only, but no permission package is installed. Every verified user has the same broad access and can trigger operations such as syncs and forecast runs. Roles and provisioning remain required."

    # 20. Evidence
    $slide = New-Slide $presentation 'What has been verified in the current system' 'DELIVERY EVIDENCE' 'Counts show breadth; reconciliations and measured runs show integration. Neither proves adoption or real-demand accuracy.'
    Stat $slide 'LATEST LOGGED PEST SUITE' '253 / 253' 'Application behavior + grain isolation' 48 145 198 126 $c.Green
    Stat $slide 'PYTHON TESTS' '62 / 62' 'Last fully logged service suite' 270 145 198 126 $c.Teal
    Stat $slide 'SOURCE BREADTH' '36 models' '43 migrations | 34 services | 33 facades' 492 145 198 126 $c.Blue
    Stat $slide 'USER EXPERIENCE' '45 React pages' 'Responsive Bootstrap + dark mode' 714 145 198 126 $c.Purple
    Panel $slide 'INTEGRATION EVIDENCE' "All 3 grains exactly reconciled`r`n0 unmatched demand SKUs`r`n2,300 forecasts in 2m22s`r`nDashboard and forecast performance measured" 48 309 410 148 $c.Blue $c.White 10.3
    Panel $slide 'EVIDENCE BOUNDARY' "Generated demand history`r`nTraining orchestration not tested in CI`r`nRecommendations read legacy inventory`r`nRoles and production operations unverified" 502 309 410 148 $c.Amber $c.AmberSoft 10.3
    Footer $slide 20
    Notes $slide "The latest logged Laravel suite passed 253 tests; the last fully logged Python suite passed 62. More important than file counts: all grains reconcile, demand has no unmatched SKU and the forecast run completes at widened scale. Keep the evidence boundary visible."

    # 21. Decisions
    $slide = New-Slide $presentation 'Five decisions turn the demo into a production service' 'READINESS & OWNERSHIP' 'The remaining work is data ownership, access policy, validation and reliable operation.'
    $decisions = @(
        @{X=48; Y=143; N='1'; T='CONNECT'; B='Production BuyAbans URL, Passport client and agreed sync load.'; C=$c.Blue},
        @{X=342; Y=143; N='2'; T='MAP STOCK'; B='Define inventory-source-to-location truth, then rebuild recommendation inputs.'; C=$c.Red},
        @{X=636; Y=143; N='3'; T='CONTROL ACCESS'; B='Roles, module permissions, operational triggers and account provisioning.'; C=$c.Amber},
        @{X=195; Y=301; N='4'; T='VALIDATE MODELS'; B='Use real history, scored horizons and a formal rule for changing the default.'; C=$c.Purple},
        @{X=489; Y=301; N='5'; T='OPERATE'; B='Hosting, database/cache, backups, mail, workers, cron, Python and monitoring.'; C=$c.Green}
    )
    foreach ($item in $decisions) {
        $null = Box $slide $item.X $item.Y 276 132 $c.White $item.C $true $true
        Badge $slide $item.N ($item.X + 17) ($item.Y + 16) $item.C 36 10
        $null = TextBox $slide $item.T ($item.X + 65) ($item.Y + 17) 185 28 12.5 $c.Navy $true
        $null = TextBox $slide $item.B ($item.X + 18) ($item.Y + 61) 240 55 9.5 $c.Muted $false 1 1
    }
    Pill $slide 'CURRENT POSITION: management demo / controlled pilot - not production decision automation' 157 461 646 34 $c.Navy $c.White 9.3
    Footer $slide 21
    Notes $slide "Ask for five explicit decisions: production connectivity, stock mapping, roles, real-history model validation and production operations. Until they are owned, position the app as ready for a management demo and controlled pilot, not automated production decisions."

    # 22. Live demo
    $slide = $presentation.Slides.Add($presentation.Slides.Count + 1, 12)
    $slide.FollowMasterBackground = 0
    $slide.Background.Fill.Solid()
    $slide.Background.Fill.ForeColor.RGB = $c.Navy
    $null = TextBox $slide 'LIVE DEMO' 50 34 300 22 10 $c.TealSoft $true
    $null = TextBox $slide 'A clear eight-minute route' 47 62 700 45 29 $c.White $true 1 1
    $demo = @(
        @{N='1'; T='DASHBOARD'; B='Trade, stock position and forecast health'},
        @{N='2'; T='BUYABANS SYNC'; B='Connection, stages and run history'},
        @{N='3'; T='PRODUCTS + VARIANTS'; B='Tree, normalized axes and SKU identity'},
        @{N='4'; T='FORECASTS'; B='Headline, range, confidence and basis'},
        @{N='5'; T='FORECAST RUNS'; B='Queued processing and completed evidence'},
        @{N='6'; T='RECOMMENDATIONS'; B='Show workflow - state the stock-source gap'}
    )
    for ($i=0; $i -lt $demo.Count; $i++) {
        $x = 48 + (($i % 3) * 294)
        $y = 132 + ([Math]::Floor($i / 3) * 126)
        $accentColor = $(if ($i -eq 5) { $c.Amber } else { $c.Teal })
        $null = Box $slide $x $y 270 102 $c.White $accentColor $true
        Badge $slide $demo[$i].N ($x + 15) ($y + 15) $accentColor
        $null = TextBox $slide $demo[$i].T ($x + 59) ($y + 14) 190 28 11.5 $c.Navy $true
        $null = TextBox $slide $demo[$i].B ($x + 17) ($y + 55) 236 34 8.8 $c.Muted $false 1 1
    }
    $null = TextBox $slide 'Close with the accurate story:' 55 404 320 28 14 $c.TealSoft $true
    $null = TextBox $slide 'BuyAbans remains operational truth. This application turns that truth into visible performance, explainable forecasts and governed decisions.' 55 440 842 55 15 $c.White $true 1 1
    Footer $slide 22 $true
    Notes $slide "Start with the management outcome, prove data provenance in BuyAbans Sync, show the corrected product tree, then the forecast experience and run evidence. End on Recommendations and state the unresolved stock mapping."

    $presentation.SaveAs($pptxPath, 24)
    $presentation.SaveAs($pdfPath, 32)

    foreach ($currentSlide in @($presentation.Slides)) {
        $path = Join-Path $previewDirectory ('slide-{0:D2}.png' -f $currentSlide.SlideIndex)
        $currentSlide.Export($path, 'PNG', 1600, 900)
    }

    Get-ChildItem -LiteralPath $imageDirectory -File -Filter '*.png' | Remove-Item -Force
    $infographics = @{
        3='01-end-to-end-system-flow.png'; 4='02-responsibility-boundary.png'
        6='03-buyabans-sync-process.png'; 7='04-demand-grain-reconciliation.png'
        8='05-catalog-normalization-process.png'; 9='06-sparse-demand-quality-process.png'
        12='07-forecast-governance-process.png'; 15='08-recommendation-data-gap.png'
        17='09-automation-timeline.png'; 18='10-technical-architecture.png'
        21='11-production-decisions.png'
    }
    foreach ($item in $infographics.GetEnumerator()) {
        $presentation.Slides.Item([int] $item.Key).Export((Join-Path $imageDirectory $item.Value), 'PNG', 1600, 900)
    }

    Write-Output "Created: $pptxPath"
    Write-Output "Created: $pdfPath"
    Write-Output "Slides: $($presentation.Slides.Count)"
    Write-Output "Infographics: $($infographics.Count)"
} finally {
    if ($presentation) { $presentation.Close() }
    if ($powerPoint) { $powerPoint.Quit() }
    if ($presentation) { [void] [System.Runtime.InteropServices.Marshal]::ReleaseComObject($presentation) }
    if ($powerPoint) { [void] [System.Runtime.InteropServices.Marshal]::ReleaseComObject($powerPoint) }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
