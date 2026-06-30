import BranchController from './BranchController'
import PackageController from './PackageController'
import ShopSettingController from './ShopSettingController'
import MemberController from './MemberController'
import PurchaseController from './PurchaseController'
import RedemptionController from './RedemptionController'

const Admin = {
    BranchController: Object.assign(BranchController, BranchController),
    PackageController: Object.assign(PackageController, PackageController),
    ShopSettingController: Object.assign(ShopSettingController, ShopSettingController),
    MemberController: Object.assign(MemberController, MemberController),
    PurchaseController: Object.assign(PurchaseController, PurchaseController),
    RedemptionController: Object.assign(RedemptionController, RedemptionController),
}

export default Admin